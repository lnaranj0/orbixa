# Tareas: 08-agent-registry

> Mantener tareas sin marcar hasta contar con evidencias de código, CI y pruebas.

- [ ] 1. Crear registro de agentes scoped por tenant, capacidades, dueño humano y política por proyecto.
- [ ] 2. Permitir asignación a tarea con owner humano y permisos no superiores a su delegante.
- [ ] 3. Importar ejecuciones externas verificadas, idempotentes y evidencia redactada/sanitizada.
- [ ] 4. Exponer UI de estado/error/costo opcional y auditoría de cambios.
- [ ] 5. Probar que no existe endpoint ni worker que dispare ejecución autónoma en esta fase.

## Gates de entrega
- [ ] Pruebas de aislamiento entre dos organizaciones sobre PostgreSQL real.
- [ ] Pruebas de permisos permitidos/denegados para roles y alcances aplicables.
- [ ] Revisión automática de límites modulares en CI y contratos de integración.
- [ ] Documentar migraciones, rollback, cambios de API y evidencia de funcionamiento.
