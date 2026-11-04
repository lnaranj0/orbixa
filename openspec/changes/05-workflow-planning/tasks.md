# Tareas: 05-workflow-planning

> Mantener tareas sin marcar hasta contar con evidencias de código, CI y pruebas.

- [x] 1. Definir máquinas de estados por proyecto, transiciones con guardas y permisos, WIP y auditoría.
  - Evidencia:
    - `WorkflowSchemaTest`: un único estado inicial de categoría backlog, categoría inmutable, `done` exige `work.approve` (trigger), sin cruces entre workflows.
    - `WorkflowTransitionsTest`: workflow por defecto, aprobación (un asignado no cierra su tarea), rechazo de transiciones no configuradas auditado tras el rollback, motivos, bloqueadores, WIP y WIP con dos transiciones simultáneas.
    - `WorkflowConfigurationTest`: la configuración solo restringe, versionado (409) y `workflow.updated`; no se archiva el estado inicial, uno con items ni el último `done`.
    - `WorkflowReviewTest` (M1, M2, M4).
- [x] 2. Mantener la pertenencia a ciclos opcional (en Planning, D-B); soportar continuo, sprint e híbrido sin duplicar registros.
  - Evidencia: `CyclesTest` (en modo continuo no se crean ciclos y el backlog funciona sin ellos; ciclos en modo sprint) y `PlanningSchemaTest` (sin `cycle_id` en items, un ciclo abierto por item).
- [x] 3. Implementar ciclos, capacidad opcional, prioridades y cierre/reapertura con historial.
  - Evidencia:
    - `CyclesTest`: fechas, objetivo y capacidad; un ciclo activo; cierre con `delivered`/`spilled` y arrastre de lo no terminado y no cancelado, con su origen.
    - `WorkflowTransitionsTest`: reabrir conserva todo el historial tipado.
    - `PlanningSchemaTest`: un ciclo cerrado no admite items.
- [x] 4. Calcular throughput, lead time, cycle time y aging desde eventos reales sin contar puntos como productividad individual.
  - Evidencia: `FlowMetricsTest` cubre:
    - métricas calculadas desde el historial `work.item_status_changes`;
    - un cancelado no cuenta como entrega y un reabierto cuenta una sola vez;
    - cycle time desde la primera entrada en `active`;
    - sin sumas de estimación ni métricas por persona (D-G, M3).
- [x] 5. Aplicar autorización de lectura a paneles agregados, incluido visor.
  - Evidencia:
    - `FlowMetricsTest`: un miembro solo ve sus proyectos visibles y un proyecto oculto da 404; el visor ve su alcance de grants (sin grants `user`), y un alcance vacío no da cifras.
    - `WorkflowReviewTest` (B9): las métricas de `restricted` quedan auditadas.
    - `WorkflowPlanningScreensTest`: pantallas.

## Gates de entrega
- [x] Pruebas de aislamiento entre dos organizaciones sobre PostgreSQL real.
  - Evidencia: suite completa (308 tests) contra `postgres-test` como `orbixa_app`, sin BYPASSRLS; `WorkflowSchemaTest` y `PlanningSchemaTest` con otra organización.
- [x] Pruebas de permisos permitidos/denegados para roles y alcances aplicables.
  - Evidencia: `WorkflowTransitionsTest`, `WorkflowConfigurationTest`, `CyclesTest`, `FlowMetricsTest`, `WorkflowReviewTest` y `ScopedRoleTemplatesTest`.
- [x] Revisión automática de límites modulares en CI y contratos de integración.
  - Evidencia: CI del PR #6 (run 41911626430) en verde con PHP 8.4 y 8.5 sobre PostgreSQL 18: Pint, PHPStan, deptrac sin violaciones (incluidas las capas `WorkWorkflowContracts` y `WorkPlanningContracts`), `TrustedReadersBoundaryTest` y suite completa como `orbixa_app`.
- [x] Documentar migraciones, rollback, cambios de API y evidencia de funcionamiento.
  - Evidencia: `docs/API.md` (sección 05), `docs/EVENTS.md` (payloads 05), `docs/DATA_MODEL.md` y `docs/WORKFLOWS.md`. Migraciones `2026_11_07_000100`–`000800` roll-forward, aplicadas en pruebas y desarrollo con backup previo; las de datos se verificaron sobre los proyectos de prueba.
