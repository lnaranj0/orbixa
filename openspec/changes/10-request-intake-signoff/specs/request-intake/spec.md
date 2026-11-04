# Capability: request-intake

## Requirement: solicitud auditable
El sistema SHALL registrar solicitudes de desarrollo y cambios de regla con identidad de creador y solicitante por separado, organización, área, tipo, título, justificación, fecha de solicitud y recepción, prioridad sugerida y evidencias.

### Scenario: solicitud capturada en nombre de otra persona
GIVEN una analista autorizada en la organización A y una solicitante distinta válida en A
WHEN la analista registra una solicitud a nombre de la solicitante
THEN el sistema conserva `created_by` y `requested_by` por separado, registra `submitted_at` y la atribución original no cambia con ediciones posteriores.

### Scenario: identidad externa
GIVEN un contacto externo verificado vinculado a la organización A
WHEN el capturista lo registra como solicitante
THEN la solicitud conserva la relación y snapshot de nombre, cargo, área y medio de contacto sin conceder acceso de miembro automáticamente.

## Requirement: tipos configurables
La organización SHALL poder catalogar solicitudes `development`, `enhancement`, `bug`, `business_rule_change`, `addr` y personalizadas sin hardcodear el significado de `addr`.

### Scenario: regla de negocio
GIVEN una solicitud tipo cambio de regla
WHEN se envía a validación
THEN se exigen regla actual, regla propuesta, casos de excepción, justificación, alcance e impacto esperado; se permite fecha de entrada en vigor.

### Scenario: falta información
GIVEN una solicitud incompleta
WHEN se intenta aprobar
THEN se deniega la transición y se indican campos faltantes sin producir documento firmable.

## Requirement: enlace al trabajo
El sistema SHALL permitir vincular una solicitud con múltiples elementos de trabajo sin convertirla automáticamente en tarea.

### Scenario: desarrollo posterior
GIVEN una solicitud aprobada
WHEN el responsable vincula dos work items
THEN la solicitud preserva su identidad y versiones y los elementos muestran la referencia de origen.

## Requirement: aislamiento
### Scenario: acceso entre organizaciones
GIVEN un usuario de organización B sin membresía ni permiso en A
WHEN consulta, filtra o solicita exportación de solicitudes de A
THEN la operación se deniega sin revelar metadatos, conteos ni documentos de A.
