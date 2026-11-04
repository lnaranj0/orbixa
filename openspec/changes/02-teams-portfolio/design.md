# Diseño: 02-teams-portfolio

Decisiones D1–D18 aprobadas el 2026-11-04. Las propusieron los agentes `architect` y `security-reviewer`.

## Contexto y decisiones
- Base de datos: PostgreSQL 18, `uuid` (uuidv7), `timestamptz`, `jsonb` solo para metadatos no relacionales.
- Arquitectura: monolito modular con capas `Domain`, `Application`, `Infrastructure`, `Presentation`, `Contracts`, `Database/Migrations`, `Tests`.
- Todo registro lleva `organization_id`, sigue la plantilla RLS de 00 y usa FK compuestas `(organization_id, x_id)`. Las migraciones son roll-forward y su `down()` lanza excepción.
- Eventos por outbox con payload de solo IDs.

## Módulos (D12)
- **Teams** (`teams.*`):
  - Tablas: `teams`, `team_members`, `team_parent_changes`.
  - Contratos: `TeamDirectory` (`activeTeamIdsOf(membership)`, `isActive(team)`, `descendantsOf(team)`; 04 reutilizará `descendantsOf` para `include_descendants`) y `TeamsManifest`.
- **Portfolio** (`portfolio.*`):
  - Tablas: `projects`, `project_members`, `project_team_links`.
  - Contratos: `ProjectVisibility` (`canView(actor, project)`, `accessibleProjects(actor)`), `ProjectDirectory` y `PortfolioManifest`.
- **Dirección de dependencia:** Portfolio usa los contratos de Teams; Teams no conoce proyectos. Por eso `project_team_links` vive en Portfolio y no en `teams`, a diferencia de lo que decía `DATA_MODEL.md` (ya actualizado).
- **Diferido:**
  - `project_counters` a 03;
  - `milestones` a 12, que define `project_milestones`;
  - `project_repositories` a 06;
  - `team_closure` no se crea;
  - `access.project_role_grants` no se crea, porque los roles con alcance viven en el módulo dueño.
- **Contrato nuevo en IdentityTenancy (D13):** `MembershipLookup::lockActive(membership)` hace `SELECT … FOR SHARE` dentro de la transacción. Así nadie se agrega a un equipo o proyecto mientras se revoca su membresía (`RevokeMembership` bloquea con `FOR UPDATE`).

## Jerarquía de equipos (D7)
- **Modelo:** lista de adyacencia con `parent_team_id`, FK compuesta al mismo tenant y `CHECK (parent_team_id <> id)`.
- **Anticiclos:**
  - `pg_advisory_xact_lock` por organización al crear o mover un equipo;
  - un CTE recursivo sobre los ancestros del nuevo padre;
  - un trigger `SECURITY INVOKER` que repite la comprobación como segunda barrera.
- **Profundidad máxima 5.** Al mover un equipo se valida `profundidad(nuevo padre) + 1 + altura(subárbol) ≤ 5`.
- **Archivado:** `status active|archived`.
  - No se archiva un equipo con hijos activos.
  - Un equipo archivado no recibe miembros, enlaces ni hijos.
  - El runtime no tiene `DELETE`.
- **Historial:** los cambios de padre quedan en `teams.team_parent_changes`, de solo inserción.

## Membresías de equipo y de proyecto (D8, D9)
- Las filas son temporales: `assigned_at/by` y `removed_at/by/reason`. Nunca se borran; son el historial.
- Un índice único parcial `WHERE removed_at IS NULL` impide tener dos filas activas para la misma persona.
- `team_members.role` es `member|lead`; `project_members.role` es `member|manager`.
- La FK compuesta a `identity.organization_memberships` se acepta, igual que en Access.
- Al revocar una membresía de organización, Teams y Portfolio implementan `OrganizationLifecycle::membershipRevoking` y cierran sus filas activas con `removed_reason='membership_revoked'` en la misma transacción.

## Proyectos y visibilidad (D1, D3, D5, D17)
- **`projects`:**
  - `key` con formato `^[A-Z][A-Z0-9]{1,9}$`, única por organización e **inmutable** (sin grant de UPDATE);
  - `type general|software|hybrid`;
  - `status active|on_hold|archived`;
  - `confidentiality standard|restricted`, alineado con el `visibility_level` de 04.
- **La visibilidad se decide en la aplicación, con un único predicado** (`ProjectVisibility`). Un proyecto es visible si hay membresía activa en la organización y además se cumple una de estas:
  - (a) `projects.manage` a nivel de organización (owner/admin, también para `restricted`, con auditoría);
  - (b) membresía directa activa en el proyecto;
  - (c) el proyecto es `standard` y el usuario es miembro activo de un equipo **enlazado directamente**, sin herencia por ancestros ni descendientes (D2).
- **RLS** sigue aislando solo por organización (`RlsCatalogTest`). Todo listado, detalle, búsqueda, conteo, API y job pasa por `accessibleProjects`.
- **Respuestas:**
  - no visible, inexistente o de otra organización: 404 idéntico;
  - visible pero sin permiso de gestión: 403;
  - clave duplicada: error genérico, con rate limit. El riesgo de enumeración queda aceptado (D11).

## Permisos con alcance (D4, D14)
- **Access** añade:
  - un objeto de valor `Scope` (`TeamScope`, `ProjectScope`);
  - un contrato `ScopedRoleProvider` (tag) que implementan Teams y Portfolio;
  - `Authorizer::canWithin(actor, permission, Scope)`. Primero consulta el permiso a nivel de organización y, si no, los roles con alcance.
- `can()` no cambia y las plantillas de 01 se quedan en `template_version` 1.
- **Traducción de roles con alcance:**
  - lead → `teams.manage` sobre su equipo **y sus descendientes**, nunca sobre ancestros ni hermanos;
  - manager → `projects.manage` sobre su proyecto.
- **Antiescalada (D6):**
  - nadie se agrega a sí mismo;
  - designar a un lead o a un manager exige el permiso a nivel de organización;
  - un lead no designa a otros leads;
  - enlazar un equipo a un proyecto exige gestionar ambos;
  - solo owner, admin o el manager enlazan proyectos `restricted`.
- **Directorio de equipos (D15):** cada miembro ve sus equipos; el inventario completo exige `teams.manage`.

## Eventos (D16)
`team.created`, `team.moved`, `team.archived`, `team.member.assigned`, `team.member.removed`, `project.created`, `project.updated`, `project.member.added`, `project.member.removed`, `project.team.linked` y `project.team.unlinked`. Solo IDs, por outbox, en la misma transacción.

## APIs (D10)
- **Rutas:** `/api/v1/organizations/{organization}/teams|projects`, como en `docs/API.md`.
- **Autenticación:** sesión + CSRF con `ResolveActiveOrganization`. Los tokens quedan para un cambio posterior.
- **Paginación:** keyset sobre `(name, id)` o `(key, id)`, con cursor opaco validado y sin `total`.
- **Filtros:** lista blanca, siempre intersectada con la visibilidad.
- **Escritura:** POST con `Idempotency-Key` y PATCH con `expected_version` (409 si no coincide). Un ID de otra organización devuelve el mismo 422 que un ID inexistente.
- **UI (D18):** pantallas mínimas provisionales hasta `11-design-system`.

## Riesgos y decisiones pendientes
- Los hooks síncronos de ciclo de vida alargan la transacción de revocación.
- Pasar `uuid[]` de equipos en el predicado puede degradarse con muchos equipos; hay que medirlo.
- Hay que confirmar el trigger anticiclos con RLS forzada y el rol runtime.
- Revisar retención y confidencialidad antes de introducir exportaciones o adjuntos.

## Verificación de salida
Un usuario puede estar en varios equipos sin cruzar tenants. Las jerarquías no contienen ciclos y los proyectos respetan la confidencialidad.
