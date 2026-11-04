# Design — ProjectManagement

## Ubicación y límites
`Modules/ProjectManagement/{Domain,Application,Infrastructure,Presentation,Contracts,Database,Tests}`. Módulo Portfolio es propietario de la identidad de `project`. ProjectManagement crea agregados de planeación vinculados a `project_id` y expone casos de uso mediante contratos; Work es propietario de actividades.

## Entidades (UUID y organization_id)
- `project_charters` (project_id, owner_id, sponsor_id, purpose, objectives, success_criteria, approved_at, revision)
- `project_scope_items` (project_id, code, statement, included, acceptance_criteria, version)
- `project_deliverables` (project_id, name, owner_actor_id, due_at, status, acceptance_status)
- `project_milestones` (project_id, name, planned_at, actual_at, state)
- `project_schedule_baselines` (project_id, revision, snapshot_jsonb, approved_by, approved_at, hash)
- `project_dependencies` (predecessor_project_id, successor_project_id, type, notes) con validación de ciclo
- `project_risks` (project_id, title, probability, impact, response, owner_actor_id, state)
- `project_issues` (project_id, title, severity, owner_actor_id, state, resolution)
- `project_decisions` (project_id, title, decision, rationale, decided_by, decided_at)
- `project_budgets` (project_id, currency, baseline_amount, current_approved_amount, revision)
- `project_cost_entries` (project_id, amount, currency, incurred_at, external_reference, recorded_by)
- `project_resource_allocations` (project_id, actor_id, allocation_percent, starts_at, ends_at)
- `project_progress_snapshots` (project_id, measured_at, status, progress_percent, health_rag, explanation)
- `project_closures` (project_id, accepted_by, closed_at, summary, lessons_learned, evidence_ref)

## Integración
- `project_id` único compartido entre dominios; `project.type` controla habilitación de capacidades, no acceso.
- Hitos y entregables pueden enlazar IDs de Work sin replicar sus estados.
- Solicitud `REQ` aprobada puede originar proyecto o change request; snapshots de baseline son inmutables.
- `Monitoring` decide visibilidad incluso de presupuestos y exportación; agregar permiso separado para datos financieros sensibles.
- Eventos outbox: `ProjectCharterApproved`, `ProjectBaselineApproved`, `ProjectRiskRaised`, `ProjectMilestoneCompleted`, `ProjectScopeChangeRequested`, `ProjectClosed`.
- Comandos idempotentes, validación de tenant, índices `(organization_id, project_id)` y RLS, límites por rol.

## Vistas
Portafolio por salud y responsable; ficha proyecto con tabs compactos (resumen, actividades, timeline, entregables, riesgos, presupuesto, cambios, documentos), roadmap/Gantt ligero, timeline por hito, tablero de tareas, reportes de estado.

## Definiciones
- Progreso: no inferir solamente por tareas terminadas. Se obtiene por hitos o entregables ponderados configurados y se muestra su método.
- Presupuesto: moneda, permisos y fuente de origen. No presentar estimaciones como gastos contabilizados.
- Alertas: riesgos vencidos, entregables sin dueño, hitos próximos o retrasados, cambios pendientes de firma.
