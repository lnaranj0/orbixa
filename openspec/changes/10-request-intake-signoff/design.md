# Diseño de Requests y RequirementDocuments

## Límites modulares
`Requests` es dueño de solicitudes, solicitantes, revisiones, clasificación, evaluación y solicitudes de cambio. `RequirementDocuments` es dueño de plantillas, snapshots, documentos, PDF, adjuntos de firma y actas de aceptación. `Work` es dueño de tareas; `Requests` conserva vínculos mediante contrato `WorkLinkService` y eventos. `Audit` recibe eventos sin usar tablas ajenas como dependencia directa.

Estructura para ambos módulos: `Domain/`, `Application/`, `Infrastructure/`, `Presentation/`, `Contracts/`, `Database/Migrations/`, `Tests/`.

## Identidades
`created_by_membership_id` = quien capturó; `requested_by_party_id` = quien hizo la petición; `requester_name_snapshot`, `requester_position_snapshot`, `requester_area_snapshot`, `requester_email_snapshot` = datos tal como constaban al formalizar. La parte solicitante puede ser miembro de tenant o contacto externo verificado. `submitted_at` y `received_at` son timestamps distintos. Guardar actor y zona horaria de captura, persistiendo UTC.

## Tipos y reglas
Catálogo configurable por tenant: `development`, `enhancement`, `bug`, `business_rule_change`, `addr` y custom. Para cambio de regla: comportamiento actual, regla propuesta, motivo, casos de excepción, vigencia, dependencias y escenarios antes/después. ADDR no tiene semántica codificada sin definición del negocio.

## Estados (Requests)
`draft -> submitted -> triage -> clarification_required -> triage -> specified -> pending_signoff -> approved/rejected -> scheduled -> in_progress -> delivered -> accepted/returned -> closed`; cancelación con motivo desde estados autorizados. Una solicitud `approved` es conformidad sobre alcance documentado, **no** aprobación automática de presupuesto ni despliegue.

## Documentos y versionado
Cada emisión genera `requirement_document_version` inmutable con número, snapshot JSONB normalizado de campos, plantilla/version, PDF, SHA-256, fecha UTC, solicitante, aprobadores y estado. No se sobrescribe el PDF firmado. Correcciones crean versión siguiente; toda modificación material posterior a firma vuelve a `pending_signoff` y exige firma de la nueva versión, preservando la anterior. Bloquear edición de snapshot firmado. Adjuntos guardados en almacenamiento privado cifrado o protegido, con URL temporal autorizada y checksum.

## Firma
MVP: `wet_ink` (imprimir, firmar y adjuntar escaneo) y `acknowledgement` interno (confirmación autenticada y auditada), **distinguidos explícitamente**; la confirmación interna no se presenta como equivalente a una firma electrónica avanzada. Más adelante `external_e_signature` vía proveedor con validación de identidad, consentimiento, certificado/evidencia y revisión legal aplicable. Registrar nombre, cargo, tipo de firma, hash del documento, signatario, fecha, IP y user agent cuando proceda, actor de carga, evidencia y resultado de verificación. Una firma se asocia exclusivamente a `document_version_id` y `document_hash`.

## Datos y seguridad
Todas las tablas tenant-scoped con `organization_id`, RLS activo, FKs coherentes y políticas Laravel. Descarga de documentos y evidencias pasa por autorización a nivel de recurso y clasificación confidencial. Visores no ven firmas/PII por defecto salvo permiso `requirements.documents.view_sensitive`; no se infiere derecho de descarga por tener acceso a métricas. Retención y borrado configurables sin romper exigencias de auditoría; evitar borrar documentos formalizados por cascada.

## Fiabilidad
Crear snapshot + outbox de evento en una transacción; PDF en worker idempotente con clave `(document_version_id, template_version)`; hash después de generar PDF, y estado `ready_for_signature` solo con PDF listo. Optimistic lock para cambios; idempotency key para altas y aprobaciones. Evitar envío duplicado, carreras de firma, y versiones superpuestas. Guardar evidencia de aceptación de entrega por separado de firma de alcance.

## UI
Bandeja `Solicitudes`, formulario asistido, responsable de levantamiento, ficha con timeline, comparador de versiones, vista previa imprimible y descarga de PDF; panel `Pendiente de mi firma`, `Pendiente de información`, `Mis solicitudes`, y tablero de vencimientos. Indicadores por solicitante, área, tipo, aprobación, demora y entregas. Reportes respetan aislamiento y permisos.
