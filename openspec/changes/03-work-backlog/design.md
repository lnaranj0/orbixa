# Diseño: 03-work-backlog

Decisiones W1–W14 aprobadas el 2026-11-04 (aprobación general del responsable para este cambio). Siguen los patrones de 02 (D1–D18).

## Contexto y decisiones
- Base de datos: PostgreSQL 18, `uuid` (uuidv7), `timestamptz`, `jsonb` solo para metadatos no relacionales (diff de actividad).
- Arquitectura: monolito modular con capas `Domain`, `Application`, `Infrastructure`, `Presentation`, `Contracts`, `Database/Migrations`, `Tests`.
- Todo registro lleva `organization_id`, sigue la plantilla RLS de 00 y usa FK compuestas `(organization_id, x_id)`. Las migraciones son roll-forward y su `down()` lanza excepción. El runtime no tiene `DELETE`.
- Eventos por outbox con payload de solo IDs y nombres de campo.

## Módulo y esquema (W1)
- **Work** (`work.*`), dueño de sus tablas; usa los contratos de Portfolio (visibilidad, referencia de proyecto), Teams (`TeamDirectory`), IdentityTenancy (`MembershipLookup`, `CurrentMembership`) y Access (`Authorizer`, `Scope`).
- Tablas:
  - `project_counters`: siguiente número por proyecto (`DATA_MODEL.md` lo movió de Portfolio a 03).
  - `items`: `project_id`, `number`, `key` (`<PROYECTO>-<n>`, denormalizada: la clave de proyecto es inmutable), `type`, `parent_id`, `title`, `description`, `status`, `priority`, `estimate`, `due_on`, `completed_at`, `created_by_membership_id`, `lock_version`.
  - `labels` (por proyecto) e `item_labels` (filas temporales).
  - `item_assignments` (filas temporales; `role owner|collaborator`).
  - `item_team_links` (filas temporales).
  - `item_dependencies` (filas temporales; `blocker_item_id` bloquea a `blocked_item_id`).
  - `item_events`: actividad append-only (solo `SELECT, INSERT` para runtime).
- **Discrepancias con `DATA_MODEL.md`, resueltas así:**
  - `type_id`/`item_types` configurables → en 03 `type` es texto con CHECK de 7 valores; un catálogo configurable queda para un cambio posterior.
  - `workflow_status_id` → en 03 `status` es la **categoría global** de `WORKFLOWS.md`; 05 añadirá `workflow_status_id` manteniendo la categoría.
  - `item_assignments.actor_type/actor_id` polimórfico → en 03 solo `membership_id` (humano); 08 añadirá `agent_id` con CHECK "exactamente uno".
  - `item_comments` e `item_attachments` → fuera de alcance de 03 (no están en `tasks.md`).

## Tipos y jerarquía (W2)
- Tipos: `initiative`, `epic`, `feature`, `task`, `bug`, `chore`, `incident`.
- Rango: initiative 1, epic 2, feature 3, task/bug/chore/incident 4. Un hijo debe tener rango mayor que su padre; además una `task` puede tener subtareas de rango 4.
- Padre del **mismo proyecto**, sin ciclos, profundidad máxima 5. Trigger `SECURITY INVOKER` con advisory lock por proyecto, igual que Teams.

## Estado sin workflow configurable (W3)
- `status` ∈ `backlog, ready, active, review, done, canceled` (categorías de `WORKFLOWS.md`). Nace en `backlog`.
- Transiciones permitidas en 03: el flujo base de `WORKFLOWS.md` (Backlog→Ready→Active→Review→Done, Review→Active, Done→Active con motivo, cualquiera no terminal→Canceled con motivo, Canceled→Backlog con motivo).
- `done` fija `completed_at`; reabrir lo limpia. 05 sustituirá el grafo fijo por workflows configurables.

## Prioridad, estimación, etiquetas (W4)
- `priority` ∈ `none, low, medium, high, urgent`; `estimate numeric(6,2) >= 0` opcional; `due_on date` opcional.
- Etiquetas por proyecto (nunca compartidas entre proyectos): nombre 1–50, único por proyecto sin distinguir mayúsculas; máximo 20 por item.
- Cada cambio queda en `item_events` con autor y fecha.

## Visibilidad (W5)
- Un item es visible si y solo si su proyecto es visible (`ProjectVisibility`), incluidos los `restricted`. Sin visibilidad propia por asignación: un asignado que deja de ver el proyecto deja de ver sus items.
- Listados, detalle, "Mi trabajo", actividad, dependencias y padres pasan por el predicado. No visible, inexistente o de otra organización: 404 idéntico.
- Abrir el detalle de un item de un proyecto `restricted` por rol de organización registra `audit.sensitive_access.granted` (operación `work.view`) a través del contrato de Portfolio.

## Permisos (W6)
- `work.create`: permiso de organización (owner, admin, project_manager, team_lead, developer) o `project.manager` del proyecto, sobre proyecto visible y no archivado.
- `work.edit`, `work.transition`: organización (owner, admin, project_manager), `project.manager` del proyecto, líder de un equipo (o ancestro) asociado al item, o **asignado activo** del item (owner o colaborador). Los caminos de asignado y de líder solo valen para roles de trabajo (con `work.create` de organización): `viewer` y `reviewer` nunca editan, aunque los asignen o lideren un equipo (revisión de seguridad M1).
- `work.assign`: organización (owner, admin, project_manager), `project.manager` del proyecto o líder de un equipo asociado al item. Nadie se asigna a sí mismo.
- `reviewer` y `viewer` no editan en 03 (las guardas de revisión llegan con 05; los grants de visor con 04).
- `ScopedRoleTemplates`: `project.manager` añade `work.create`, `work.edit`, `work.transition`, `work.assign`. Las plantillas de rol de organización no cambian.

## Asignaciones y equipos (W7)
- Un único `owner` activo por item (índice único parcial) y colaboradores sin duplicados.
- El asignado debe tener membresía activa (bloqueada `FOR SHARE`), un rol de trabajo (`work.create` de organización) **y** poder ver el proyecto; si no, el mismo 422 genérico que una membresía inexistente o ajena. Límites: 50 asignados y 20 equipos por item, 200 etiquetas por proyecto.
- Equipos asociados: equipo activo de la organización (trigger `FOR SHARE`). La asociación **no** da visibilidad.
- Revocar la membresía de organización cierra sus asignaciones activas (`OrganizationLifecycle`).

## Dependencias (W8)
- Solo entre items del **mismo proyecto** en 03 (evita revelar items de proyectos no visibles). Sin ciclos (trigger con advisory lock por proyecto), sin autodependencia, máximo 50 activas por item.

## Actividad (W9)
- `item_events` append-only con `actor_membership_id`, `occurred_at`, `type` y `changes jsonb`.
- Diff sanitizado: valores antiguos y nuevos para campos estructurados (estado, prioridad, estimación, fecha, tipo, padre, título); para `description` solo `{changed: true, length}`; nunca emails ni nombres.

## Concurrencia (W10)
- `lock_version` y `expected_version` → 409 sin pisar cambios.
- Claves secuenciales: `insert … on conflict do update … returning` sobre `project_counters` serializa por proyecto; `UNIQUE (organization_id, project_id, number)` como segunda barrera.

## API y pantallas (W11)
- Rutas bajo `/api/v1/organizations/{organization}` (sesión + CSRF, como 02):
  - `GET/POST /projects/{project}/items`, `GET/POST /projects/{project}/labels`;
  - `GET/PATCH /items/{item}`, `POST /items/{item}/transitions`;
  - `POST/DELETE` de asignaciones, etiquetas, equipos y dependencias del item;
  - `GET /items/{item}/activity`, `GET /me/work`.
- Keyset con cursor firmado, orden de lista blanca (`number`, `priority`, `updated_at`), filtros de lista blanca después de la visibilidad, sin totales. `Idempotency-Key` opcional en POST de creación; `throttle:writes` en toda escritura.
- Pantallas mínimas (backlog del proyecto, ficha con actividad, "Mi trabajo", formulario de creación) hasta `11-design-system`. Las descripciones se muestran como texto escapado; sin markdown ni HTML en 03.

## Eventos (W12)
`work.item.created`, `work.item.updated`, `work.item.assigned`, `work.item.transitioned`, `work.item.blocked`, `work.item.completed`, `work.item.reopened`. Solo IDs y nombres de campo; nunca título ni descripción.

## Proyectos archivados (W13)
Un proyecto `archived` no admite items nuevos ni cambios en sus items (422). `on_hold` sí.

## Incrementos (W14)
1. Spec y diseño. 2. Esquema núcleo (contadores, items, etiquetas, actividad). 3. Esquema de relaciones (asignaciones, equipos, dependencias). 4. Casos de uso de items. 5. Casos de uso de relaciones y ciclo de vida. 6. Consultas y API. 7. Pantallas. 8. Revisión de seguridad, docs y evidencia.

## Riesgos y decisiones pendientes
- El grafo fijo de estados se sustituye en 05; hay que migrar `status` a `workflow_status_id` conservando la categoría.
- Dependencias entre proyectos quedan fuera; si se piden, requieren visibilidad de ambos lados.
- Revisar retención y confidencialidad antes de introducir comentarios, adjuntos o exportaciones.

## Verificación de salida
Se crea y finaliza una tarea real sin GitHub/OpenSpec; ordenamientos y vistas respetan tenant y permisos.
