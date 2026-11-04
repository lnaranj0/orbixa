# Tareas: 01-identity-tenancy

> Mantener tareas sin marcar hasta contar con evidencias de código, CI y pruebas.

- [ ] 1. Crear identidad global, organización, membership y sesiones por contexto activo.
- [ ] 2. Gestionar invitaciones, membresías múltiples, activación y revocación; control de acceso deny-by-default.
- [ ] 3. Implementar políticas de roles por organización y contexto sin permitir autoescalamiento.
- [ ] 4. Aplicar RLS FORCED a tablas de organización y probar pools y trabajos asíncronos.
- [ ] 5. Probar usuario en dos organizaciones, UUID ajeno, pertenencia revocada y transacción sin tenant.

## Gates de entrega
- [ ] Pruebas de aislamiento entre dos organizaciones sobre PostgreSQL real.
- [ ] Pruebas de permisos permitidos/denegados para roles y alcances aplicables.
- [ ] Revisión automática de límites modulares en CI y contratos de integración.
- [ ] Documentar migraciones, rollback, cambios de API y evidencia de funcionamiento.
