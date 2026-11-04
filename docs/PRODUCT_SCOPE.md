# Orbixa — Alcance funcional y límites de producto (v5)

Orbixa es una plataforma multi-organización de **Project Management** y **Engineering Management**. No todo proyecto es software y no todo proyecto utiliza sprints.

## Núcleo compartido
- IdentityTenancy y Access: organizaciones, membresías, permisos y políticas de visibilidad delegada.
- Teams: equipos, subequipos, responsables y colaboración transversal.
- Portfolio: portafolios, proyectos, programas, estado ejecutivo y dependencias.
- Work: actividades, jerarquía, asignaciones, fechas, comentarios, archivos, dependencias y eventos.
- Workflow/Planning: estados configurables, vistas Kanban, cronograma, hitos y sprints opcionales.
- Monitoring: grants explícitos para ver trabajo de un usuario, equipo (descendientes opcionales) u organización.
- Requests/RequirementDocuments: peticiones, reglas de negocio, ADDR configurable, documentos versionados y firmas.
- Audit/Reporting: bitácoras, trazabilidad, KPIs y reportes con autorización por fila.

## Capacidades específicas
- ProjectManagement: proyecto general, plantilla por tipo, charter, alcance, objetivos, entregables, cronograma, hitos, dependencias, riesgos, incidencias, decisiones, presupuesto, costos, avance, baseline, cambios y cierre.
- Engineering: spec-driven, OpenSpec, repositorios GitHub, PR, tareas técnicas, releases, agentes IA.

## Tipos de proyecto
- `general`: proyecto operativo, administrativo, comercial o de implementación sin requerir GitHub/OpenSpec.
- `software`: agrega capacidades de ingeniería sobre el mismo `project_id`.
- `hybrid`: combina entregables generales y actividades técnicas.
- Tipos configurables por organización en una fase posterior, sin duplicar tabla de proyectos.

## Modelo de uso
- Una actividad pertenece a un proyecto y se puede vincular con un entregable o un hito; un proyecto puede tener dependencias con otros proyectos.
- Los recursos y asignaciones se comparten entre ProjectManagement y Work; no hay duplicados de tareas.
- El cambio de tipo de proyecto habilita capacidades mediante feature flags y autorización; no elimina información ni cambia IDs.
- Sprints opcionales y exclusivos de flujos que los necesiten; la ejecución continua está disponible siempre.
- Solicitudes firmadas pueden originar un proyecto general, un proyecto software o actividades en un proyecto existente.
- Una solicitud formal y una tarea son entidades diferentes y mantienen trazabilidad bidireccional.

## Aislamiento
Todas las entidades de negocio llevan `organization_id`; filtros y RLS por tenant. Acceso de visor nunca equivale a edición; métricas y exportaciones respetan la misma política.

## No objetivos del MVP
Contabilidad completa, ERP, nómina, facturación, motor BPMN avanzado, edición colaborativa en tiempo real, ejecución autónoma de agentes, equivalencia legal automática de firma electrónica.
