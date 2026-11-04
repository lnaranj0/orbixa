# Capability: requirement-documents

## Requirement: hoja de requerimiento formal
El sistema SHALL emitir una hoja imprimible en PDF con folio, versión, solicitante, fecha/hora, organización, área, proyecto relacionado, tipo, descripción, objetivo, comportamiento actual y esperado, alcance, exclusiones, criterios de aceptación, impactos, prioridades, adjuntos referenciados, responsables, tabla de firmas y notas de aprobación.

### Scenario: generar documento
GIVEN una solicitud especificada con campos obligatorios completos
WHEN un actor autorizado emite la versión 1
THEN se guarda snapshot inmutable, PDF y hash SHA-256, identificador de plantilla, emisor y fecha; solo después se habilita `ready_for_signature`.

### Scenario: firma autógrafa
GIVEN una hoja lista para firma
WHEN se imprime, firma y se carga el escaneo
THEN el sistema conserva el PDF original, el escaneo, hash y metadatos de carga y vincula la evidencia a la versión específica.

### Scenario: se modifica después de firma
GIVEN la versión 1 firmada
WHEN cambia el alcance o criterio de aceptación
THEN se crea versión 2 en espera de nueva aprobación, la versión 1 conserva su archivo y evidencia, y no se reutilizan firmas.

## Requirement: firmas claramente identificadas
El sistema SHALL distinguir firma autógrafa escaneada, confirmación interna autenticada y futura firma electrónica de proveedor; no SHALL etiquetar confirmación interna como firma electrónica avanzada.

### Scenario: firma inválida por versión
GIVEN evidencia de firma perteneciente a versión 1
WHEN se intenta asociar a versión 2
THEN se rechaza la operación por discrepancia de `document_version_id` o hash.

## Requirement: descarga segura
### Scenario: visor de equipo sin permiso documental sensible
GIVEN un visor con grant válido de equipo pero sin `requirements.documents.view_sensitive`
WHEN solicita el escaneo firmado de una solicitud del equipo
THEN se deniega acceso al archivo y no se expone URL persistente.
