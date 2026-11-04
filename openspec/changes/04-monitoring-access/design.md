# Diseño: 04-monitoring-access

Decisiones M1–M11 aprobadas el 2026-11-04. Las propusieron los agentes `architect` y `security-reviewer`. Siguen los patrones de 02 (D1–D18) y 03 (W1–W14).

## Contexto y decisiones
- Base de datos: PostgreSQL 18, `uuid` (uuidv7), `timestamptz`, `jsonb` solo para metadatos no relacionales.
- Arquitectura: monolito modular con capas `Domain`, `Application`, `Infrastructure`, `Presentation`, `Contracts`, `Database/Migrations`, `Tests`.
- Todo registro lleva `organization_id`, sigue la plantilla RLS de 00 y usa FK compuestas. Las migraciones son roll-forward y su `down()` lanza excepción. El runtime no tiene `DELETE`.
- Eventos por outbox con payload de solo IDs.

## Módulo y esquema (M1)
- Nuevo módulo **Monitoring** (`monitoring.*`), separado de Access como pide la spec:
  - `monitoring.grants`: `viewer_membership_id`, `scope_type user|team|organization`, `target_membership_id` o `target_team_id` (destino tipado, CHECK de exactamente un destino según el scope y ninguno para `organization`), `include_descendants`, `visibility_level standard|restricted`, `granted_by_membership_id`, `starts_at`, `expires_at`, `revoked_at/by`, `revoked_reason`, `reason`, `lock_version`.
  - `monitoring.grant_events`: historial append-only (alta, edición, revocación, rechazo) con actor, motivo y cambio.
- **Discrepancias resueltas:**
  - `DATA_MODEL.md` decía `access.monitoring_grants`; se corrige a `monitoring.grants` (la spec manda sobre el doc).
  - `tasks.md` decía `viewer_user_id` y destino polimórfico (`target_type/target_id`); se usan membresías y columnas tipadas para tener FK compuestas.
- Las FK hacia `identity.organization_memberships` y `teams.teams` son dependencias de base de datos entre módulos, con el mismo precedente que `access.membership_roles` y `portfolio.project_team_links`.

## 403 al mutar (M2, opción b)
- Work define en sus contratos `ReadOnlyItemAccess` (proveedores tagged) y Monitoring lo implementa.
- Solo en las rutas de escritura de Work: si el item no es visible por su proyecto pero algún proveedor confirma que un grant vigente lo cubre, responde **403** y se audita. Si no lo cubre, 404 idéntico.
- Las lecturas de Work no cambian: un grant nunca se añade a `ProjectVisibility` ni a `WorkGuard`.

## Permisos y migración de roles (M3, opción A; M4)
- Permisos nuevos en Access:
  - `monitoring.view`: viewer;
  - `monitoring.grants.view`, `monitoring.grants.manage`, `monitoring.restricted.view`: owner y admin;
  - `monitoring.export`: nadie.
- `RoleTemplates::VERSION = 2`. Las organizaciones nuevas nacen con los permisos.
- Las organizaciones existentes se actualizan con una migración de datos: dentro de su transacción, `NO FORCE ROW LEVEL SECURITY` en `access.roles` y `access.role_permissions`, inserción de los permisos que faltan en los roles base con `template_version = 1`, paso de esos roles a `template_version = 2` y vuelta a `FORCE`. Es atómica: si algo falla, se revierte todo.
- Owner y admin ven el panel con alcance de organización implícito, coherente con `ProjectVisibility` (a). El viewer, nunca sin grant.

## Reglas de concesión (M5)
- Requiere `monitoring.grants.manage`. Un grant `restricted` exige además `monitoring.restricted.view` en quien lo concede.
- El viewer debe ser miembro activo con `monitoring.view`; nadie se concede a sí mismo.
- Destino de otra organización, inexistente o inactivo (membresía revocada, equipo archivado): el mismo 422 genérico, y el intento queda auditado (`monitoring.grant.rejected`) sin datos de la otra organización.
- El request nunca fija `organization_id`, `granted_by`, `revoked_at` ni `lock_version`. Edición optimista con `expected_version` (409).
- `expires_at` posterior a `starts_at`, con duración máxima de 365 días.

## Alcance (M6)
- `user` U: items donde U es owner o colaborador activo (`work.item_assignments`).
- `team` T: items con vínculo activo a T (`work.item_team_links`); con `include_descendants`, también a sus subequipos activos, calculados en cada lectura (`TeamDirectory::descendantsOf`).
- `organization`: todos los items.
- Confidencialidad por grant: cada grant aporta su conjunto de proyectos. Los `standard` siempre; los `restricted` solo con `visibility_level = restricted`. La unión de varios grants se hace cláusula a cláusula, sin cruzarla con `ProjectVisibility` del viewer.
- Siempre solo lectura. El detalle de monitoreo es un DTO propio y filtrado: sin otros asignados, dependencias, equipos ni descripción fuera del alcance.

## Aislamiento entre módulos (M7)
- Monitoring no lee tablas ajenas. Work expone `MonitoredWorkReader` (lectura con un `ItemSelector` definido en Work) y Portfolio, `ProjectCatalog::idsByConfidentiality()`.
- Son lecturas "de confianza", sin actor: van en capas deptrac propias (`WorkMonitoringContracts`, `PortfolioCatalogContracts`) que solo Monitoring puede usar.
- Las rutas `/monitoring/*` solo admiten GET (más la administración de grants, protegida por `monitoring.grants.manage`).

## Vigencia y revocación (M8)
- Cada lectura evalúa en SQL `now()` entre `starts_at` y `expires_at`, `revoked_at is null`, membresía activa del viewer y del destino, equipo activo y que el viewer siga teniendo `monitoring.view`. Sin caché de alcance.
- `OrganizationLifecycle::membershipRevoking` cierra los grants donde la membresía es viewer o destino (`revoked_reason = membership_revoked`).
- Los cursores se ligan al viewer y a la huella del conjunto de grants vigentes: tras una revocación o un grant nuevo, un cursor viejo es inválido (422).

## Auditoría y eventos (M9)
- `grant_events` registra cada operación con actor, motivo y diff.
- Outbox: `monitoring.grant.created`, `monitoring.grant.updated`, `monitoring.grant.revoked`, `monitoring.grant.rejected`. Payload solo con IDs, scope, nivel y vigencia.
- Las lecturas por grant `restricted` u `organization` se auditan (`audit.sensitive_access.granted` con `operation: monitoring.view`, deduplicado por actor y ventana).
- `monitoring.grant.expired` queda diferido: requiere un job que recorra todas las organizaciones. La expiración se aplica igual en cada lectura.

## API y pantallas (M10)
- Rutas bajo `/api/v1/organizations/{organization}/monitoring`:
  - `GET /overview`: conteos por estado y prioridad, solo dentro del alcance y sin totales globales;
  - `GET /items` (keyset, filtros de lista blanca), `GET /items/{item}`;
  - `GET /teams/{team}` y `GET /users/{member}`: el subconjunto del alcance que cubre ese equipo o persona; 404 si ningún grant lo cubre;
  - `GET/POST /grants`, `PATCH /grants/{grant}`, `POST /grants/{grant}/revoke`.
- Pantallas mínimas: panel del visor y administración de grants.
- **Sin endpoint de exportación en 04.** El permiso `monitoring.export` existe, nadie lo tiene y un test lo verifica; la exportación espera a la revisión de retención.

## Fuera de alcance (M11)
Comentarios, adjuntos y búsqueda de texto aún no existen; en `tasks.md` se marcan como no aplicables, con justificación. Sus módulos deberán aplicar el mismo alcance cuando lleguen.

## Incrementos
1. Diseño y spec. 2. Permisos y migración de roles. 3. Esquema `monitoring`. 4. Casos de uso de grants y hooks de revocación. 5. Contratos de Work y Portfolio con capas deptrac. 6. Servicio de visibilidad y consultas. 7. 403 en escrituras de Work. 8. API y pantallas. 9. Revisión de seguridad, docs y evidencia.

## Riesgos y decisiones pendientes
- Mover un equipo amplía en silencio el alcance de los grants con descendientes; queda registrado en `teams.team_parent_changes`.
- Las lecturas sin actor (M7) son el punto más sensible: solo Monitoring puede usarlas y siempre con un selector construido desde grants vigentes.
- Revisar retención y confidencialidad antes de habilitar exportaciones o adjuntos.

## Revisión de seguridad (aplicada)
- **M-1:** índice de deduplicación de auditoría en la outbox (`2026_11_06_000300`), descendientes activos en una consulta, máximo 20 grants activos por visor, `throttle:reads` en las lecturas.
- **M-2:** un grant revocado es inmutable también en base de datos (`2026_11_06_000400`); un grant expirado no se reactiva al editarlo.
- **M-3:** la auditoría del 403 se escribe tras el rollback de la transacción de la petición y nunca tapa el 403.
- **Decisiones documentadas:**
  - los intentos sin permiso o de autoconcesión dan 403 sin auditar (la spec exige auditar los cruces entre organizaciones);
  - un grant sigue vigente si quien lo emitió pierde el rol; un equipo archivado deja su grant sin efecto;
  - código y migración v2 se despliegan juntos;
  - owner/admin no auditan los listados de proyectos `restricted`, igual que en Work.
- En producción, índices sobre tablas grandes como la outbox deben crearse con `CONCURRENTLY`, fuera de transacción.

## Verificación de salida
Viewer sin grant obtiene cero datos; grants muestran solo el alcance permitido y nunca autorizan escritura.
