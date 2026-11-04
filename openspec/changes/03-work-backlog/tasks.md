# Tareas: 03-work-backlog

> Mantener tareas sin marcar hasta contar con evidencias de código, CI y pruebas.

- [x] 1. Implementar work_items, tipos, jerarquía, referencias por proyecto, prioridades y etiquetas.
  - Evidencia:
    - `WorkSchemaTest`: claves `KEY-n` únicas e inmutables, contador serializado por proyecto, padre del mismo proyecto, tipos compatibles, ciclos y profundidad 5, etiquetas por proyecto, proyecto archivado.
    - `WorkItemsTest`: claves `ORBX-1`/`ORBX-2`, jerarquía mediante los casos de uso, prioridad, estimación y etiquetas con historial.
- [x] 2. Crear asignaciones múltiples con un owner principal, colaboradores y asociaciones de equipo.
  - Evidencia:
    - `WorkRelationsTest`: un único owner que reemplaza al anterior, colaboradores sin duplicados, sin autoasignación, 422 genérico para miembros sin acceso, ajenos o inexistentes, revocación que cierra asignaciones, equipos activos que no dan visibilidad.
    - `WorkSecurityReviewTest` (M1): viewer y reviewer nunca editan, aunque los asignen o lideren un equipo asociado.
- [x] 3. Implementar dependencias no cíclicas, historial append-only y concurrencia optimista.
  - Evidencia:
    - `WorkSchemaTest`: dependencias sin ciclos ni autodependencia, solo en el mismo proyecto, concurrencia serializada; actividad sin UPDATE ni DELETE.
    - `WorkSecurityReviewTest` (M2): una cadena de 1003 eslabones no puede cerrar un ciclo.
    - `WorkItemsTest`: 409 con versión obsoleta sin pisar el cambio; diff sanitizado sin el texto de la descripción.
- [x] 4. Construir backlog, ficha de actividad, Mi trabajo, filtrado/paginado y pruebas de autorización.
  - Evidencia:
    - `WorkApiTest`: crear y finalizar una tarea por HTTP, paginación por cursor sin totales, orden y filtros de lista blanca, 404 idéntico (oculto, ajeno, inexistente, por clave), "Mi trabajo" sin proyectos que ya no ve, auditoría de `restricted`.
    - `WorkScreensTest`: crear y finalizar una tarea desde las pantallas; viewer de solo lectura.
    - `WorkItemsTest`: matriz de permisos (organización, project manager, asignado, líder de equipo asociado, viewer, ajeno).
- [x] 5. Emitir eventos por outbox sin acoplamiento a componentes externos.
  - Evidencia: `work.item.*` registrados en la misma transacción; las pruebas comprueban que los payloads no contienen títulos, descripciones ni motivos (`WorkItemsTest`, `WorkRelationsTest`).

## Gates de entrega
- [x] Pruebas de aislamiento entre dos organizaciones sobre PostgreSQL real.
  - Evidencia: suite completa (269 tests) contra `postgres-test` como `orbixa_app`, sin BYPASSRLS; `WorkSchemaTest` y `WorkApiTest` con datos de otra organización.
- [x] Pruebas de permisos permitidos/denegados para roles y alcances aplicables.
  - Evidencia: `WorkItemsTest`, `WorkRelationsTest`, `WorkSecurityReviewTest`, `WorkScreensTest`.
- [x] Revisión automática de límites modulares en CI y contratos de integración.
  - Evidencia: CI del PR #4 (run 41885022029) en verde con PHP 8.4 y 8.5 sobre PostgreSQL 18: Pint, PHPStan, deptrac sin violaciones y suite completa como `orbixa_app`.
- [x] Documentar migraciones, rollback, cambios de API y evidencia de funcionamiento.
  - Evidencia: `docs/API.md` (sección 03), `docs/EVENTS.md` (payloads 03), `docs/DATA_MODEL.md`. Migraciones `2026_11_05_000100`–`000300` roll-forward, aplicadas en pruebas y desarrollo con backup previo.
