# Tareas: 04-monitoring-access

> Mantener tareas sin marcar hasta contar con evidencias de código, CI y pruebas.

- [ ] 1. Crear monitoring.grants con viewer_membership_id, organization_id, scope y destino tipado (target_membership_id / target_team_id), include_descendants, visibility_level, vigencia, issuer y estado (M1).
- [ ] 2. Implementar MonitoringAccessPolicy y visibilidad centralizada por usuario/equipo/organización combinando grants vigentes.
- [ ] 3. Exigir pertenencia al tenant y límites de confidencialidad; default deny; no inferir acceso de rol viewer.
- [ ] 4. Implementar UI de visores solo lectura y administración auditada de grants; exportación separadamente autorizada.
- [ ] 5. Probar listas, detalle, agregados, métricas, búsquedas, API, adjuntos, exportaciones, caché y revocación inmediata.

## Gates de entrega
- [ ] Pruebas de aislamiento entre dos organizaciones sobre PostgreSQL real.
- [ ] Pruebas de permisos permitidos/denegados para roles y alcances aplicables.
- [ ] Revisión automática de límites modulares en CI y contratos de integración.
- [ ] Documentar migraciones, rollback, cambios de API y evidencia de funcionamiento.
