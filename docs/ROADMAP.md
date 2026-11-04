# Plan de implementación y criterios de salida

## Fase 0: Fundaciones
**Resultado:** repo + app base Laravel, CI, PostgreSQL 18, Redis, módulos, pruebas con DB real, políticas base y telemetría.
**Exit:** despliegue reproducible con autenticación funcional; migraciones idempotentes; baseline de seguridad.

## Fase 1: MVP multi-tenant y backlog
**Resultado:** organizaciones, miembros, equipos/subequipos, proyectos, tareas jerárquicas, workflows, Kanban, Mi trabajo, actividad y permisos.
**Exit:** 2 organizaciones con usuarios multi-tenant; pruebas cruzadas sin fugas; flujo crear->asignar->revisar->cerrar y auditar; UI móvil operable.

## Fase 2: GitHub híbrido, OpenSpec y planificación
**Resultado:** GitHub App, repo selection, webhook inbox, reconciliación, PR/commit, OpenSpec index, vínculos, ciclos opcionales y analítica base.
**Exit:** PR + change OpenSpec vinculados a item; delivery repetido sin duplicación; fallo externo recuperable; project modo continuo e híbrido operables.

## Fase 3: Agentes IA registrados y trazabilidad
**Resultado:** agent catalog, capabilities, assignments, external execution reports, evidencia y revisión humana.
**Exit:** registrar agente y vincular ejecución a work item sin habilitar ejecución desde EMP; logs y permisos validados.

## Fase 4: Ejecución segura y automatización avanzada
**Resultado:** gateway opcional, runners efímeros, aprobaciones humanas, automatizaciones configurables, capacidad e indicadores avanzados.
**Exit:** sandbox, scoped credentials, policy checks, aprobación explícita, revocación, pruebas de abuso, pruebas DR.

## Decomposición posterior de OpenSpec
El cambio maestro guía el programa. Antes de codificar, dividirlo en cambios pequeños: `foundation-tenancy`, `teams-and-projects`, `work-items-and-workflow`, `kanban-and-activity`, `planning-cycles`, `github-app-sync`, `openspec-indexer`, `agent-registry`, `execution-import`, `agent-runner` (futuro). Cada cambio con propuesta, delta specs, design y tasks, PR y criterios propios.

## Riesgos que requieren decisión previa a producción
Volumen de tenants, SLA, RPO/RTO, identidad corporativa/SSO y MFA, costos/quotas, políticas de retención y residencia de datos, despliegue por entorno, backups y auditoría contractual.

## Ajustes aprobados de plataforma
- **PostgreSQL 18** reemplaza PostgreSQL en todo el desarrollo nuevo: migrations `pgsql`, RLS, pruebas de conexión real, backups y ensayo de restauración.
- **Modularidad no negociable:** CI valida límites entre módulos; `Monitoring` y `Access` son módulos independientes que consumen contratos públicos de Work, Teams y Portfolio.
- **Fase 0:** adicionar esquema de RLS, pool/conexiones, roles diferenciados migración/runtime, y pruebas de bypass/aislamiento.
- **Fase 1:** incluir viewer de persona, equipo (subequipos opcionales) y toda la organización, vistas de monitoreo read-only, grants con revocación y auditoría. No posponer la seguridad de viewer a fases posteriores.
- **Fase 2:** GitHub/OpenSpec en monitoreo respetan alcances; no filtrar repositorios privados o secretos mediante metadatos.


## Extensión v4 — solicitudes formales y firma
**Fase 1, track paralelo con backlog**: implementar `10-request-intake-signoff` tras `01-identity-tenancy` y `02-teams-portfolio`. Entregable mínimo: solicitudes por área/persona, diferencia entre solicitante y capturista, tipos de cambio de regla/ADDR, registro de fechas, hoja PDF imprimible, firmas autógrafas adjuntas y versiones no sobrescribibles.
**Criterio de salida adicional de Fase 1:** solicitud con folio e identidad, formulario de regla actual/propuesta, PDF V1, firma adjunta ligada al hash, cambio material que genera V2 y firma nueva, vínculo a work items, autorización y aislamiento probados.
**Ampliación posterior:** integración con proveedor externo de firma electrónica tras definición jurídica, identidad del firmante, retención y evidencia exigida.

## Orbixa v5: secuencia recomendada
- Fase 0: `00-platform-foundation`; iniciar `11-design-system` tras la estructura base.
- Fase 1: `01-identity-tenancy`, `02-teams-portfolio`, `03-work-backlog`, `04-monitoring-access`, `10-request-intake-signoff`.
- Fase 1B: `12-project-management` (núcleo de charter, hitos, riesgos y seguimiento). Presupuesto y recursos pueden entregarse en subiteración posterior del mismo OpenSpec.
- Fases 2+: workflows, GitHub, OpenSpec y agentes según los cambios existentes. La gestión general de proyectos NO depende de GitHub/OpenSpec.
