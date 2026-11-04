# Tareas: 01-identity-tenancy

> Mantener tareas sin marcar hasta contar con evidencias de código, CI y pruebas.

- [x] 1. Crear identidad global, organización, membership y sesiones por contexto activo.
  - Evidencia:
    - `identity.users` con uuid v7 (`IdentityUsersTest`).
    - Organizaciones y membresías con RLS (`OrganizationMembershipTest`).
    - Organización activa por ruta con regeneración de sesión (`HttpFlowsTest`).
- [x] 2. Gestionar invitaciones, membresías múltiples, activación y revocación; control de acceso deny-by-default.
  - Evidencia: `InvitationsAndRevocationTest`, que cubre:
    - solo se guarda el hash del token;
    - expiración, un solo uso, email verificado y distinto;
    - el invitador se revalida al aceptar;
    - rate limiting;
    - revocación inmediata y reingreso.
  - También el flujo HTTP completo (`HttpFlowsTest`).
- [x] 3. Implementar políticas de roles por organización y contexto sin permitir autoescalamiento.
  - Evidencia: `AccessRolesTest` y `RoleAssignmentPolicyTest`.
    - Gate deny-by-default.
    - Nadie toca sus propios roles.
    - Solo se conceden roles cuyos permisos tiene el actor.
    - `org_owner` solo lo otorga o revoca un owner, y nunca se quita el último.
    - El viewer no tiene ningún permiso.
- [x] 4. Aplicar RLS FORCED a tablas de organización y probar pools y trabajos asíncronos.
  - Evidencia:
    - `RlsCatalogTest`: plantilla canónica, solo 3 funciones definer revisadas y policies nominales del lector.
    - `TenantIsolationTest`: conexión reutilizada.
    - `TenantAwareJobsTest`: el job revalida la membresía, falla cerrado tras revocación y no filtra contexto al siguiente job.
- [x] 5. Probar usuario en dos organizaciones, UUID ajeno, pertenencia revocada y transacción sin tenant.
  - Evidencia:
    - `OrganizationMembershipTest`: dos organizaciones y 0 filas sin contexto.
    - `HttpFlowsTest`: 404 idéntico para UUID ajeno, inexistente o malformado.
    - `InvitationsAndRevocationTest` y `TenantAwareJobsTest`: revocación.

## Gates de entrega
- [x] Pruebas de aislamiento entre dos organizaciones sobre PostgreSQL real.
  - Evidencia: suite completa (188 tests) contra `postgres-test` como `orbixa_app`.
- [x] Pruebas de permisos permitidos/denegados para roles y alcances aplicables.
  - Evidencia: `AccessRolesTest`, `InvitationsAndRevocationTest` y `HttpFlowsTest` (403 y 404).
- [x] Revisión automática de límites modulares en CI y contratos de integración.
  - Evidencia: CI del PR #2 (run `41769101598`, commit `198f332`) en PHP 8.4 y 8.5.
    - deptrac con capas IdentityTenancy y Access, sin violaciones.
    - Larastan nivel 8 `[OK] No errors`.
    - 188 tests y 675 aserciones.
- [x] Documentar migraciones, rollback, cambios de API y evidencia de funcionamiento.
  - Evidencia: `design.md` (decisiones, rutas HTTP, jobs) y `docs/operations/database-roles.md` (rol lector).
  - Las migraciones son solo roll-forward, y cada una se aplicó con backup previo en desarrollo.
