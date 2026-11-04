# Changes implementables

Este directorio contiene **once cambios independientes** con dependencias explícitas en `DEPENDENCIES.json`. La versión anterior del cambio maestro se conserva como referencia en `docs/reference/bootstrap-engineering-platform-v2` y **no debe aplicarse como un cambio activo**.

## Orden sugerido
1. `00-platform-foundation`
2. `01-identity-tenancy`
3. `02-teams-portfolio`
4. `03-work-backlog`
5. `04-monitoring-access`
6. `05-workflow-planning` y `06-github-integration` en paralelo cuando el núcleo esté listo
7. `07-openspec-traceability` tras GitHub
8. `08-agent-registry`
9. `09-agent-automation` (requiere nueva aprobación de alcance en fase 4)

## Nota de implementación
`04-monitoring-access` se implementa sobre entidades de Work; el rol viewer sin grants carece de visibilidad. Las concesiones a usuario, equipo y organización permanecen dentro del tenant y sujeta a confidencialidad.


## Fase 1 adicional: `10-request-intake-signoff`
Se puede implementar tras `01-identity-tenancy` + `02-teams-portfolio`, en paralelo con `03-work-backlog`. El enlace a tareas se realiza mediante contrato público una vez disponible Work. Solicitante y capturista pueden diferir. Hoja de requerimiento y firma quedan vinculadas a una versión inmutable. El tipo ADDR es configurable, sin definición de negocio asumida.

## Orbixa v5 additions
- `11-design-system` depends on `00-platform-foundation`; applies across every view, including existing modules.
- `12-project-management` depends on identity, teams, work, monitoring, requests and DesignSystem; does NOT depend on GitHub/OpenSpec.
- General, software and hybrid project types use one Portfolio project and one Work engine.
