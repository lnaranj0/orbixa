# Matriz RBAC / ABAC

Roles base: platform_operator (infra, sin lectura universal), org_owner, org_admin, team_lead, project_manager, developer, reviewer, viewer, integration_service y agent_identity (sin login humano). Los roles son plantillas configurables; políticas comprueban también membresía, propiedad, estado y tenant.

| Permiso | Owner/Admin | Project manager | Team lead | Developer | Reviewer | Viewer | Agent |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `organization.manage` | Sí | No | No | No | No | No | No |
| `members.invite` | Sí | Config. | No | No | No | No | No |
| `teams.manage` | Sí | Config. | Equipo propio | No | No | No | No |
| `projects.create` | Sí | Config. | Config. | No | No | No | No |
| `projects.manage` | Sí | Proyecto propio | No | No | No | No | No |
| `work.view` | Según proyecto | Según proyecto | Según proyecto | Según proyecto | Según proyecto | Según proyecto | Scope |
| `work.create` | Sí | Sí | Sí | Sí | No | No | No |
| `work.edit` | Sí | Sí | Equipo propio | Asignado/colaborador | No | No | No |
| `work.transition` | Sí | Sí | Equipo propio | Según workflow | Review only | No | No |
| `work.assign` | Sí | Sí | Equipo propio | No | No | No | No |
| `work.approve` | Sí | Sí | Config. | No | Según política | No | No |
| `planning.manage` | Sí | Sí | Equipo propio | No | No | No | No |
| `integrations.github.manage` | Sí | Config. | No | No | No | No | No |
| `specs.link` | Sí | Sí | Sí | Sí | No | No | No |
| `agents.manage` | Sí | Config. | No | No | No | No | No |
| `agents.assign` | Sí | Sí | Config. | No | No | No | No |
| `agents.execute` | No (fase inicial) | No | No | No | No | No | No |
| `audit.view` | Sí | Scope | Scope | No | No | No | No |

**Reglas obligatorias:** `deny by default`; scopes organizacional y proyecto; revocar membresía invalida tokens/sesiones/caché; soporte entre tenants solo con grant temporal auditado; tokens de GitHub App nunca visibles a usuarios/agents; políticas para comentarios privados y repositorios confidenciales; ningún rol de tenant puede crear privilegios cross-tenant.

**Pruebas clave**: matriz de roles x recurso propio x recurso otro tenant; endpoint por UUID adivinado; listas/paginación/búsqueda; export, jobs, attachments, webhook routing; caching multi-tenancy; role escalation; impersonation; miembros con distintos roles en distintas organizaciones.

## Viewer delegado: alcance y operaciones
`viewer` es un rol de solo lectura con **cero alcance por defecto**. La membresía organizacional es obligatoria y cada autorización de monitoreo es un `monitoring_grant` separado, con destino:
- **Usuario:** tareas donde el usuario observado sea responsable, asignado o colaborador según reglas del módulo Work; no da acceso a mensajes privados o a todo el proyecto por asociación incidental.
- **Equipo:** elementos explícitamente vinculados a ese equipo y proyectos permitidos; subequipos se incluyen solo mediante `include_descendants`.
- **Todos:** todos los elementos estándar de **esa organización**, nunca de otros tenants; recursos restringidos requieren una autorización adicional.

Permisos diferenciados: `monitoring.view`, `monitoring.grants.view`, `monitoring.grants.manage`, `monitoring.export` y `monitoring.restricted.view`. El permiso `monitoring.view` no sustituye un grant. `monitoring.grants.manage` solo para `org_owner`/`org_admin` por defecto; delegación futura por política limitada a equipo. Exportación se mantiene deshabilitada por defecto incluso para viewer con `monitoring.view`.

Política `CanMonitor(subject,resource)` = membresía activa AND permiso lectura AND grant activo vigente que cubre recurso AND política de confidencialidad permite acceso. Revisar la **misma política** para APIs, dashboards, adjuntos, exportes, vistas guardadas, notificaciones y búsquedas. Las grants nunca otorgan create/update/delete/transition/approve/manage. Auditar la emisión y revocación; usar invalidación inmediata de sesiones/caché y no informar IDs ajenos (404/403).

### Matriz específica
| Acción | Viewer con grant aplicable | Org admin autorizado |
| --- | --- | --- |
| Ver tablero y estado de actividades en alcance | Sí | Sí |
| Ver métricas agregadas en alcance | Sí, sin revelar datos fuera del alcance | Sí |
| Leer adjunto permitido del alcance | Sí, solo si clasificación lo permite | Según política |
| Exportar monitoreo | No por defecto, requiere `monitoring.export` | Configurable |
| Crear/modificar tarea, transición, comentario, asignación | No | Según permisos operativos |
| Crear o ampliar grants | No | Sí, auditado |
| Ver usuarios/proyectos ajenos u otras organizaciones | No | No, salvo propias membresías autorizadas |


## Permisos Requests y RequirementDocuments
- `requests.create`, `requests.view`, `requests.viewAny`, `requests.updateDraft`, `requests.triage`, `requests.assignAnalyst`, `requests.evaluate`, `requests.approve`, `requests.reject`, `requests.linkWork`, `requests.export`.
- `requirements.documents.create`, `requirements.documents.view`, `requirements.documents.download`, `requirements.documents.view_sensitive`, `requirements.documents.manageTemplates`, `requirements.signatures.record`, `requirements.signatures.approve`, `requirements.signatures.reject`, `requirements.delivery.accept`.
- Solicitante: acceso a sus solicitudes y documentos habilitados explícitamente por pertenencia/identidad verificada, nunca a los de otra persona por compartir área.
- Capturista: permiso de crear y consultar solicitudes creadas según política; no puede aprobar por defecto.
- Viewer: métricas/listado filtrados por grants; **sin lectura de evidencias de firma, datos personales ni descarga de documentos sensibles** a menos que tenga permiso expreso y política de recurso.
- La designación de firmante y la autorización de aprobación son requisitos simultáneos. Nadie obtiene permiso de aprobación solo por aparecer como solicitante.

## Orbixa v5: permisos Project Management
Propuesta: `projects.charter.view|manage|approve`, `projects.scope.manage|approve`, `projects.milestones.manage`, `projects.risks.view|manage`, `projects.decisions.manage`, `projects.budget.view|manage|approve`, `projects.finance.view`, `projects.resources.manage`, `projects.baseline.approve`, `projects.close`, `projects.reports.view|export`. Permisos limitados por organización/proyecto más grants de monitoreo. Los visores no obtienen automáticamente datos financieros.
