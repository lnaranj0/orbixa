# Tareas: 06-github-integration

> Mantener tareas sin marcar hasta contar con evidencias de código, CI y pruebas.

- [x] 1. Crear instalaciones GitHub App y selección explícita de repositorios por organización.
  - Evidencia (06a, incrementos 3 y 6): `InstallationSetupTest` (18 pruebas: state de un solo uso ligado a sesión, usuario y organización; acceso y administración de la cuenta verificados con OAuth; instalación de una sola organización con error genérico; selección con `integrations.github.manage`; roles de solo lectura denegados), `InstallationRoutingTest` y `HttpGitHubApiTest`, todas con el rol runtime real sobre PostgreSQL.
- [ ] 2. Validar firmas y guardar inbound webhook antes de responder; deduplicar por delivery ID y recuperar fallas.
  - Parcial (06a, incremento 5): firma, inbox antes de responder y deduplicación por delivery ID y cuerpo cubiertos por `WebhookIntakeTest`. Falta "recuperar fallas" (procesamiento del inbox con reintentos, 06b).
- [ ] 3. Proyectar PR, commits, checks y enlaces a work_items sin reemplazar backlog como fuente de verdad.
- [ ] 4. Implementar sincronización por cursor, reintentos con backoff y operación manual de reconciliación.
- [ ] 5. Probar eventos duplicados/desordenados, revocaciones, repositorios privados y ausencia de cierre implícito.

## Gates de entrega
- [ ] Pruebas de aislamiento entre dos organizaciones sobre PostgreSQL real.
- [ ] Pruebas de permisos permitidos/denegados para roles y alcances aplicables.
- [x] Revisión automática de límites modulares en CI y contratos de integración.
  - Evidencia 06a: PR #7, run 41976730135 (PHP 8.4 y 8.5 con PostgreSQL 18): deptrac sin violaciones, Larastan nivel 8 sin errores, 359 pruebas, provisioning de `orbixa_installation_reader` y reporte de catálogo. 06b debe mantenerlo en verde, incluida la capa `WorkIntegrationContracts`.
- [ ] Documentar migraciones, rollback, cambios de API y evidencia de funcionamiento.
