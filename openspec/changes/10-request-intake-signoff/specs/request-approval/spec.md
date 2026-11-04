# Capability: request-approval

## Requirement: aprobación y aceptación
El sistema SHALL manejar aprobaciones de alcance y aceptación de entrega como procesos distintos, con decisión, actor, fecha y motivo.

### Scenario: aprobación de alcance
GIVEN una versión de documento lista para firma y firmantes requeridos configurados
WHEN todos los aprobadores autorizados completan la conformidad correspondiente a esa versión
THEN la solicitud puede pasar a `approved` y conserva trazabilidad de cada aprobación.

### Scenario: rechazo
GIVEN una solicitud en `pending_signoff`
WHEN el aprobador rechaza con motivo
THEN la solicitud queda en revisión/rechazada según política, guarda la razón y no se planifica automáticamente.

### Scenario: entrega no aceptada
GIVEN una solicitud implementada y entregada
WHEN el solicitante informa que no cumple los criterios firmados
THEN se registra devolución vinculada a criterios afectados, sin modificar retroactivamente la aprobación original.

## Requirement: separación de funciones
### Scenario: aprobador no autorizado
GIVEN un usuario que creó la solicitud pero no tiene permiso ni está designado firmante
WHEN intenta aprobar el documento
THEN se deniega la operación, se registra el intento sin modificar la aprobación.

### Scenario: firma vencida
GIVEN un enlace de aprobación vencido o revocado
WHEN se intenta confirmar
THEN se rechaza sin crear evidencia de aprobación.
