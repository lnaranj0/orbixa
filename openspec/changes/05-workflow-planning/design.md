# Diseño: 05-workflow-planning

Decisiones D-A…D-G y P1–P11 aprobadas el 2026-11-04. Las propusieron los agentes `architect` y `security-reviewer`. Siguen los patrones de 02 (D1–D18), 03 (W1–W14) y 04 (M1–M11).

## Contexto y decisiones
- Base de datos: PostgreSQL 18, `uuid` (uuidv7), `timestamptz`, `jsonb` solo para metadatos no relacionales.
- Arquitectura: monolito modular con capas `Domain`, `Application`, `Infrastructure`, `Presentation`, `Contracts`, `Database/Migrations`, `Tests`.
- Todo registro lleva `organization_id`, sigue la plantilla RLS de 00 y usa FK compuestas. Las migraciones son roll-forward y su `down()` lanza excepción. El runtime no tiene `DELETE`.
- Eventos por outbox con payload de solo IDs.

## Discrepancias resueltas
- **D-A · Reviewer.** `WORKFLOWS.md` asigna Review→Done al reviewer, pero en 03 (W6) viewer y reviewer nunca editan. En 05 el reviewer sigue sin transicionar. Pasar a la categoría `done` exige `work.approve`: owner, admin y project_manager a nivel de organización, más el rol con alcance `project.manager`, añadido en `ScopedRoleTemplates` sin migración de roles. Un asignado no puede autocerrar su tarea.
- **D-B · Pertenencia a ciclos.** `tasks.md` pedía `cycle_id` nullable en `work.items`. La pertenencia vive solo en Planning (`planning.cycle_items`, con historial); un item sin ciclo no tiene fila ahí. Work no tiene `cycle_id`.
- **D-C · Workflow.** Uno por proyecto. Los workflows compartibles (`workflow.project_workflows` de `DATA_MODEL.md`) quedan para más adelante.
- **D-D · Modo.** `continuous|sprint|hybrid` se guarda en `planning.project_settings`, no en el workflow.
- **D-E · Reporting.** Módulo sin tablas: calcula métricas a partir de contratos de Work.
- **D-F · WIP.** Límite duro: la transición que lo excede se rechaza con 422, sin revelar contadores.
- **D-G · Métricas por persona.** Ninguna en 05: solo por proyecto, equipo o alcance de monitoreo.

## Módulos (P1)
- **Workflow** (`workflow.*`): `workflows` (uno por proyecto), `statuses`, `transitions`.
- **Planning** (`planning.*`): `project_settings`, `cycles`, `cycle_items`.
- **Reporting**: sin tablas.
- Work sigue siendo el único que escribe en `work.items` y su historial.

## Integración con Work (P2)
- Work define en `Work\Contracts\Workflow\` el puerto `TransitionPolicy` y Workflow lo implementa (inversión de dependencia, como `ReadOnlyItemAccess` en 04). Capa deptrac propia `WorkWorkflowContracts`.
- La política responde con DTOs: estado inicial de un proyecto, transición permitida o no, categoría destino, motivo obligatorio, comprobación de bloqueos, límite WIP del destino y permiso requerido.
- Las guardas que dependen de datos de Work (dependencias bloqueantes, conteo WIP, `completed_at`) se evalúan en Work.

## Modelo de workflow (P3)
- `statuses`: `project_id`, `category` (las seis de `WORKFLOWS.md`) **inmutable**, nombre, orden, `wip_limit` opcional y `archived_at`. Los estados se archivan, nunca se borran; no se archiva un estado con items.
- Siempre hay exactamente un estado inicial de categoría `backlog` y al menos uno de categoría `done`.
- `transitions`: origen, destino, `requires_reason`, `check_blockers` y `required_permission`.
  - El permiso sale de una lista blanca (`work.transition` o `work.approve`) y **solo restringe**: nunca amplía lo que ya permite `WorkGuard`.
  - Cualquier transición hacia `done` exige `work.approve`, la configure quien la configure.
  - Ninguna transición puede habilitar a viewer, reviewer ni al monitoreo.
- Configurar el workflow exige `work.edit` sobre el proyecto (organización o `project.manager`) y queda auditado (`workflow.updated`, con versión).

## Migración de los items existentes (P4)
- `work.items` conserva `status` como categoría (lo usan `items_completed_check`, el lector de monitoreo y los conteos) y gana `workflow_status_id`.
- Una FK compuesta `(organization_id, project_id, workflow_status_id, status) → workflow.statuses(organization_id, project_id, id, category)` garantiza que estado, proyecto y categoría coinciden, sin trigger.
- Pasos:
  1. Un workflow por defecto para cada proyecto existente, idéntico al grafo base de 03: migración de datos con `NO FORCE` temporal dentro de su transacción, como en 04 M3.
  2. Columna nullable más backfill.
  3. `SET NOT NULL`, en una migración aparte tras verificar.
- Proyectos nuevos: el workflow por defecto se asegura de forma idempotente (unique por proyecto) en la transacción que crea el primer item.

## WIP (P5)
- Límite por estado. Dentro de la transición, Work toma un advisory lock por `(proyecto, estado destino)` y cuenta después del bloqueo; la versión del item se sigue comprobando.
- Con N transiciones simultáneas hacia una columna llena, solo entra una.
- El 422 no incluye contadores ni IDs.

## Historial (P6)
- `work.item_status_changes`, append-only con columnas tipadas: estado y categoría de origen y destino, actor, instante y correlación. Se escribe en la misma transacción que la transición.
- Backfill desde `item_events` (`created` y `transitioned`). El runtime no puede hacer UPDATE ni DELETE.
- Reabrir nunca borra filas.

## Métricas (P7)
- Lead time: desde la creación hasta la última llegada a `done`.
- Cycle time: desde la primera entrada en `active` hasta la última llegada a `done`.
- Throughput: items cuya categoría actual es `done`, por fecha de su última llegada a `done`.
- Aging: tiempo de los items abiertos desde su primera entrada en `active`.
- `canceled` no cuenta como entrega. Sin sumas de estimación ni desglose por persona (D-G).

## Paneles (P8)
- Reporting usa un lector de Work ligado al actor (`FlowMetricsReader`) que filtra por `ProjectVisibility`; nunca usa los lectores de confianza.
- Los visores obtienen sus métricas en `/monitoring/metrics`, calculadas sobre su `ItemSelector` con vigencia evaluada en cada lectura (04 M8).
- Un selector vacío devuelve vacío, nunca ceros ni totales globales.

## Ciclos (P9)
- `planning.cycles`: proyecto, nombre, objetivo, fechas, `capacity` opcional, `status planned|active|closed` y `lock_version`.
- `planning.cycle_items`: filas temporales con `committed` y `outcome delivered|spilled|removed`. Un índice único parcial asegura un solo ciclo abierto por item. Las FK compuestas obligan a que item y ciclo sean del mismo proyecto.
- El cierre marca explícitamente los items no terminados (`spilled`) y puede llevarlos al ciclo siguiente (`carried_from`).
- En modo `continuous` no se crean ciclos. Gestionar ciclos exige `planning.manage` (organización o `project.manager`).

## Auditoría de transiciones ilegales (P10)
- Una transición no configurada responde 422 y registra `work.item.transition_rejected` (solo IDs) después del rollback, en una transacción propia, sin tapar el 422 (patrón 04 M-3). Hay deduplicación por ventana y throttle.
- Solo se audita sobre items visibles para un actor con permiso: lo que da 404 o 403 no se audita.

## Eventos (P11)
- `work.item.transitioned` pasa a versión 2: añade `from_status_id` y `to_status_id` y conserva las categorías.
- Nuevos: `work.item.transition_rejected`, `workflow.updated`, `planning.cycle.created`, `planning.cycle.started`, `planning.cycle.closed`, `planning.cycle.item_added` y `planning.cycle.item_removed`. Todos con solo IDs.

## Incrementos
1. Diseño y spec.
2. Esquema `workflow`.
3. Workflow por defecto para cada proyecto existente.
4. `workflow_status_id` en items y su backfill.
5. Historial y su backfill.
6. `SET NOT NULL`.
7. `TransitionPolicy`, WIP, bloqueos, `work.approve` para `done` y auditoría.
8. Gestión de workflows y su API.
9. Esquema `planning` y ciclos.
10. Métricas.
11. Pantallas, revisión de seguridad, documentación y evidencia de CI.

## Riesgos y decisiones pendientes
- El backfill del historial depende de los eventos `created` y `transitioned` de 03; si alguno faltara, el lead time de esos items se calcula desde `created_at`.
- La FK que cruza Work → Workflow fija el orden de las migraciones.
- Revisar retención y confidencialidad antes de exportar métricas.

## Verificación de salida
Proyectos con flujo continuo funcionan sin sprint; transiciones ilegales fallan y métricas no filtran otros alcances.
