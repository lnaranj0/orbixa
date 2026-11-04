# Checklist implementable — solicitudes y firmas

## Modelo y contratos
- [ ] Definir ADR de fuente de verdad de solicitudes y límites `Requests` / `RequirementDocuments`.
- [ ] Migrar tablas de solicitudes, solicitantes, revisiones, change requests, approvals, documentos/versiones y firmantes, con FK por tenant.
- [ ] Implementar RLS y tests de aislamiento en PostgreSQL real (incluyendo exportación y adjuntos).
- [ ] Registrar permisos y políticas de acceso sin asignar acceso implícito al rol viewer.

## Captura y evaluación
- [ ] Crear tipos configurables (incluye `business_rule_change` y `addr` como etiquetas distintas).
- [ ] Capturar created_by, requested_by, área, cargo, fechas y evidencia de recepción.
- [ ] Crear formulario de reglas de negocio: antes/después, excepciones y vigencia.
- [ ] Implementar transiciones con guardas, razón, idempotencia, concurrencia y auditoría.
- [ ] Vincular una solicitud aprobada con cero o más work items a través de contrato público.

## Hoja formal y firmas
- [ ] Crear plantilla versionada y snapshot inmutable; documentar campos obligatorios.
- [ ] Generar PDF para impresión y firma; guardar SHA-256 y exportación segura.
- [ ] Adjuntar escaneo firmado, identificar signatario y validar correspondencia de versión/hash.
- [ ] Implementar aprobación/rechazo por firmante y firma interna solo como acknowledgement claramente diferenciada.
- [ ] Obligar nueva emisión/aprobación si hay cambio material tras firma y conservar versiones anteriores.
- [ ] Crear acuse de aceptación de entrega independiente.

## UX y prueba
- [ ] Bandeja Solicitudes y panel de firma, filtros por solicitante/área/estado/tipo.
- [ ] Probar que creador y solicitante pueden ser personas diferentes.
- [ ] Probar denegación de acceso cross-tenant, viewer no autorizado y descarga URL expirada.
- [ ] Probar dos aprobaciones simultáneas, duplicación de trabajos PDF y reintento de uploads.
- [ ] Probar cambio de regla ADDR como tipo configurable sin significado de negocio asumido.
- [ ] Añadir contract tests con Work, Monitoring y Audit, sin accesos directos a BD de otros módulos.
