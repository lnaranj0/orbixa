# Tareas: 00-platform-foundation

> Mantener tareas sin marcar hasta contar con evidencias de código, CI y pruebas.

- [ ] 1. Crear aplicación Laravel y convenciones estrictas de módulos; CI ejecuta pruebas de arquitectura, estilo y análisis estático.
  - Hecho:
    - `modules/{Platform,Audit}` con Contracts, Infrastructure, Presentation, Database/Migrations y Tests.
    - Providers explícitos en `bootstrap/providers.php` y `ModuleManifest` por módulo.
    - deptrac sin violaciones, con un fixture de violación que hace fallar la revisión (`ModuleBoundariesTest`).
    - PHPStan nivel 8 sin errores y Pint en verde, en local.
  - Pendiente: primera ejecución real de `.github/workflows/ci.yml`, que requiere remoto y push autorizados.
- [x] 2. Configurar PostgreSQL 18 con rol de migración separado del runtime y privilegios mínimos; Redis y workers.
  - Hecho:
    - Roles `orbixa_migrator`, `orbixa_app` y `orbixa_relay` sin SUPERUSER ni BYPASSRLS, en desarrollo y en pruebas.
    - Las 9 tablas existentes, con su historial de migraciones, transferidas a `orbixa_migrator`.
    - Privilegios por tabla (`GlobalTablePrivilegesTest`, `RuntimeRolePrivilegesTest`); `orbixa:db:verify --with-migrator --with-relay` en verde.
    - Redis con contraseña (`NOAUTH` sin ella) y Predis para caché y colas.
  - Workers con Laravel Horizon, decidido el 2026-11-03. Se verificó en desarrollo que procesa un `DeliverDomainEvent` real desde Redis (`DONE`, sin jobs fallidos).
    - El dashboard se niega a todos fuera de local.
    - El scheduler ejecuta el relay y `horizon:snapshot`. Operación en `docs/operations/workers.md`.
- [x] 3. Implementar request context y transacción tenant-safe, SET LOCAL, fail-closed y RLS FORCED en tabla de prueba.
  - Evidencia: `TenantIsolationTest`, `RlsCatalogTest` y `TableOwnershipTest` sobre PostgreSQL 18 real como `orbixa_app`.
    - `TenantContext` solo se abre tras verificar la membresía.
    - El contexto se fija con `set_config(..., true)` y se limpia al liberar savepoints.
    - Un contexto vacío o inválido devuelve 0 filas sin error.
    - La tabla `platform.tenant_probe` usa FORCE RLS y su `organization_id` es inmutable.
- [x] 4. Validar idempotencia básica, outbox transaccional y trace/correlation_id en HTTP y colas.
  - Evidencia:
    - `OutboxTest`: commit y rollback junto con la mutación, aislamiento entre organizaciones, solo append.
    - `IdempotencyTest`.
    - `CorrelationIdTest`, `DeliverDomainEventTest` y el round trip de Context hacia la cola.
    - `OutboxRelayTest` y `RelayRolePrivilegesTest` (relay, opción A).
- [ ] 5. Documentar backups PITR, migraciones, rollback y recuperación probada.
  - Hecho: `docs/operations/migrations.md`, `docs/operations/backup-pitr.md` (runbook) y `docs/operations/database-roles.md`. Rollback de los pasos de aprovisionamiento documentado.
  - Pendiente: el ensayo de recuperación PITR se pospuso por decisión del 2026-11-03. Es obligatorio antes de cualquier entorno con datos reales.

## Gates de entrega
- [x] Pruebas de aislamiento entre dos organizaciones sobre PostgreSQL real.
  - Evidencia: suite `Security` (120/120 en total) contra `postgres-test` como `orbixa_app`.
- [x] Pruebas de permisos permitidos/denegados para roles y alcances aplicables.
  - Evidencia: roles de base de datos runtime, migrador y relay (`RuntimeRolePrivilegesTest`, `RelayRolePrivilegesTest`, `GlobalTablePrivilegesTest`).
  - Los roles de aplicación (owner, admin, viewer…) llegan con `01-identity-tenancy`.
- [ ] Revisión automática de límites modulares en CI y contratos de integración.
  - deptrac está configurado y probado en local; falta la primera ejecución en CI.
- [x] Documentar migraciones, rollback, cambios de API y evidencia de funcionamiento.
  - Evidencia: `docs/operations/*`. No hubo cambios de API en este cambio.
