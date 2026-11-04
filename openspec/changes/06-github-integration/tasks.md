# Tareas: 06-github-integration

> Mantener tareas sin marcar hasta contar con evidencias de código, CI y pruebas.

- [ ] 1. Crear instalaciones GitHub App y selección explícita de repositorios por organización.
- [ ] 2. Validar firmas y guardar inbound webhook antes de responder; deduplicar por delivery ID y recuperar fallas.
- [ ] 3. Proyectar PR, commits, checks y enlaces a work_items sin reemplazar backlog como fuente de verdad.
- [ ] 4. Implementar sincronización por cursor, reintentos con backoff y operación manual de reconciliación.
- [ ] 5. Probar eventos duplicados/desordenados, revocaciones, repositorios privados y ausencia de cierre implícito.

## Gates de entrega
- [ ] Pruebas de aislamiento entre dos organizaciones sobre PostgreSQL real.
- [ ] Pruebas de permisos permitidos/denegados para roles y alcances aplicables.
- [ ] Revisión automática de límites modulares en CI y contratos de integración.
- [ ] Documentar migraciones, rollback, cambios de API y evidencia de funcionamiento.
