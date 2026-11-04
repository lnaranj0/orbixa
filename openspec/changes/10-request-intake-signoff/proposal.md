# Solicitudes de desarrollo, cambios de reglas y hoja de requerimientos

## Motivación
Los cambios pueden originarse fuera del equipo técnico: áreas de negocio, responsables de operación, usuarios y representantes externos. Es necesario demostrar **quién solicitó qué y cuándo**, documentar alcance y criterios de aceptación, obtener una firma de conformidad y conservar evidencia de versiones y cambios posteriores.

## Alcance
- Nuevo bounded context `Requests` (solicitudes y trazabilidad de solicitantes).
- Nuevo bounded context `RequirementDocuments` (plantillas, versiones, PDF y evidencias de firma), con contratos explícitos hacia Requests y Audit.
- Solicitudes de desarrollo nuevo, mejora, error, cambio de regla de negocio, ADDR y otros tipos configurables por organización. `ADDR` se conserva como etiqueta configurable; no se presume significado.
- Solicitante interno o externo con identidad verificable y captura del contexto del área y organización; el solicitante puede ser distinto del creador de la solicitud.
- Flujo recepción -> levantamiento -> validación -> aprobación o rechazo -> planificación -> entrega -> aceptación.
- Hoja de requerimiento exportable a PDF, imprimible para firma autógrafa, con archivo escaneado; firma electrónica por proveedor especializado como ampliación posterior.
- Enlace opcional a proyecto/work item/OpenSpec sin convertir cada solicitud directamente en tarea.
- Visibilidad restringida por roles del módulo y alcance organizacional; vistas de monitoreo únicamente según grants y clasificación.

## Fuera de alcance de esta primera iteración
- Firma electrónica con presunción legal automática, certificados NOM-151 o validación jurídica de un proveedor.
- Generación automática de tareas, estimación IA y promesas de entrega.
- Portal público anónimo sin verificación, aunque el esquema queda listo para una futura vía externa.

## Dependencias
`00-platform-foundation`, `01-identity-tenancy`, `02-teams-portfolio`; vínculo opcional con `03-work-backlog` mediante su contrato público. Iniciar dentro de Fase 1, previo o paralelo a backlog.

## Valor
Trazabilidad completa desde la petición original hasta la entrega y su aceptación, con un documento firmado que acredita el alcance acordado de una **versión específica**.
