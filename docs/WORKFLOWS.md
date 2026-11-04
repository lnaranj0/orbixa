# Workflows, reglas y transiciones

## Modelo
`workflow` configurable por proyecto: `status` con categoría global (backlog, ready, active, review, done, canceled), nombre y orden local; `transition` con source/destination, permisos, políticas de guardia; `mode` continuous/sprint/hybrid; optional WIP limit.

## Flujo base
| Origen | Destino | Guarda / actor |
| --- | --- | --- |
| Backlog | Ready | Priorización y definición mínima, project manager/team lead |
| Ready | Active | Asignación válida, WIP, implementador |
| Active | Review | Evidencia requerida según tipo, implementador |
| Review | Active | Rechazo con motivo, reviewer |
| Review | Done | Aprobaciones satisfechas, reviewer/PM |
| Done | Active | Reabrir con motivo y permisos |
| Backlog/Ready/Active/Review | Canceled | Motivo requerido, PM |
| Canceled | Backlog | Restaurar con motivo, PM |

## Planificación
- continuous: cycle opcional (normalmente null); reabastecimiento Kanban.
- sprint: plan y cierre de ciclos, spillover explícito, reporte committed vs delivered.
- hybrid: items de mantenimiento sin sprint; iniciativas/capacidad en ciclos.
- No interpretar pertenencia a ciclo como estado de trabajo; son dimensiones distintas.

## Guardas recomendadas
- Validación de dependencia bloqueante antes de Ready/Done según política.
- `Done` exige criterio de aceptación/manual o automatizado según tipo y permiso de revisión.
- `Closed PR` no equivale a `Done` ni a deploy.
- Prioridad y estado no deben inferirse de labels GitHub sin regla declarada.
- La transición registra actor, origen, destino, motivo, fecha, correlación y versión.

## Otras máquinas de estados
- `spec_change`: detected -> indexed -> linked -> reviewed -> implemented -> archived; `error` y `needs_reconciliation`.
- `external_execution`: registered -> running -> succeeded/failed/canceled; estados repetidos son idempotentes y cambios retroactivos requieren secuencia válida.
- `webhook_delivery`: received -> verified -> queued -> processed / retryable_failed / dead_letter.

## Flujo de visibilidad de monitoreo
`requested -> active -> expired/revoked`; un administrador con `monitoring.grants.manage` crea un grant solo para un viewer miembro de su tenant, selecciona `user`, `team` o `organization`, define subequipos y vigencia, y registra motivo. Cambios de alcance requieren actualizar grant con evento auditado o revocar y crear nuevo; vencimiento/revocación se verifica al leer, no depende exclusivamente de jobs. Revocar membresía revoca acceso efectivo inmediatamente. La UI de seguimiento permite filtrar por alcance autorizado, sin modificar el workflow del elemento monitoreado.


## Ciclo formal de solicitud (v4)
`draft -> submitted -> triage -> specified -> pending_signoff -> approved/rejected -> scheduled -> in_progress -> delivered -> accepted/returned -> closed`; desde triage puede pasar a `clarification_required` y volver; cancelación con motivo según permiso.
- `specified -> pending_signoff`: exige requerimiento completo y PDF de versión generada con hash.
- `pending_signoff -> approved`: exige conformidad de firmantes requeridos para **la misma** versión. El rol de capturista no basta.
- `approved -> scheduled`: lo realiza gestor autorizado; vinculación opcional a Work; la aprobación no implica compromiso automático de plazo.
- Cambio material de regla, alcance o criterio posterior a firma: nueva `request_revision` y `document_version`, revocar vigencia operativa de la conformidad anterior sin alterar su evidencia.
- `delivered -> accepted`: acuse de entrega separado de la firma inicial. `returned` conserva motivo e incumplimientos.

## Orbixa v5: flujo general de proyecto
`idea -> proposed -> approved -> planned -> active -> on_hold -> completed -> closed`, con rutas de cancelación y reapertura auditadas. Baselines y aprobaciones inmutables; cambios materiales vía Requests. Los proyectos software pueden combinar este flujo con Kanban técnico y sprints opcionales.
