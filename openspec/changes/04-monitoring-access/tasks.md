# Tareas: 04-monitoring-access

> Mantener tareas sin marcar hasta contar con evidencias de código, CI y pruebas.

- [x] 1. Crear monitoring.grants con viewer_membership_id, organization_id, scope y destino tipado (target_membership_id / target_team_id), include_descendants, visibility_level, vigencia, issuer y estado (M1).
  - Evidencia:
    - Migración `2026_11_06_000200_create_monitoring` con plantilla RLS y FK compuestas.
    - `MonitoringSchemaTest`: un destino por scope, sin autoconcesión, vigencia de 365 días como máximo, campos inmutables para el runtime, historial append-only, aislamiento entre organizaciones.
    - `MonitoringReviewTest` (M-2): un grant revocado es inmutable en base de datos.
- [x] 2. Implementar MonitoringAccessPolicy y visibilidad centralizada por usuario/equipo/organización combinando grants vigentes.
  - Evidencia: `MonitoringVisibility` (predicado único) y `MonitoringVisibilityTest`, que cubre:
    - la matriz de visores sin grant, con grant `user`, `team` (con y sin subequipos) y `organization` (standard y restricted);
    - el alcance implícito de owner/admin;
    - conteos iguales al listado.
- [x] 3. Exigir pertenencia al tenant y límites de confidencialidad; default deny; no inferir acceso de rol viewer.
  - Evidencia:
    - `RoleTemplates` v2: el viewer solo tiene `monitoring.view`; nadie tiene `monitoring.export`.
    - Migración de datos `2026_11_06_000100` ensayada sobre 3140 organizaciones de prueba.
    - `GrantAdministrationTest`: rechazos uniformes y auditados de destinos de otra organización, inexistentes, inactivos o archivados.
    - `MonitoringVisibilityTest`: los `restricted` solo aparecen con grant `restricted`; otra organización no ve nada.
- [x] 4. Implementar UI de visores solo lectura y administración auditada de grants; exportación separadamente autorizada.
  - Evidencia:
    - `MonitoringApiTest`: panel y API de solo lectura, administración de grants por HTTP con historial, 405 en métodos no GET y ninguna ruta de exportación.
    - `ReadOnlyWritesTest`: escrituras de Work sobre items cubiertos dan 403 auditado; las no cubiertas, 404.
    - La exportación queda deshabilitada en 04 (M10): `monitoring.export` existe y nadie lo tiene.
- [x] 5. Probar listas, detalle, agregados, métricas, búsquedas, API, adjuntos, exportaciones, caché y revocación inmediata.
  - Evidencia:
    - Listas, detalle, agregados y API: `MonitoringVisibilityTest` y `MonitoringApiTest`, con conteos solo dentro del alcance y sin totales.
    - Revocación inmediata: `MonitoringVisibilityTest` (grant revocado, membresía observada revocada, subequipo movido, grant aún no vigente) y `MonitoringApiTest` (el cursor viejo da 422 tras la revocación).
    - Caché: no se guarda el alcance en caché; se recalcula en cada lectura (M8).
    - No aplica todavía (M11): búsquedas, adjuntos y comentarios no existen; sus módulos deberán aplicar el mismo alcance. Exportaciones: deshabilitadas (M10).

## Gates de entrega
- [x] Pruebas de aislamiento entre dos organizaciones sobre PostgreSQL real.
  - Evidencia: suite completa (292 tests) contra `postgres-test` como `orbixa_app`, sin BYPASSRLS; `MonitoringSchemaTest`, `MonitoringVisibilityTest` y `MonitoredWorkReaderTest` con datos de otra organización.
- [x] Pruebas de permisos permitidos/denegados para roles y alcances aplicables.
  - Evidencia: `GrantAdministrationTest`, `MonitoringApiTest`, `ReadOnlyWritesTest`, `AccessRolesTest` y `RoleTemplatesTest`.
- [x] Revisión automática de límites modulares en CI y contratos de integración.
  - Evidencia: CI del PR #5 (run 41898223879) en verde con PHP 8.4 y 8.5 sobre PostgreSQL 18: Pint, PHPStan, deptrac sin violaciones (incluidas las capas `WorkMonitoringContracts` y `PortfolioCatalogContracts`), `TrustedReadersBoundaryTest` y suite completa como `orbixa_app`.
- [x] Documentar migraciones, rollback, cambios de API y evidencia de funcionamiento.
  - Evidencia: `docs/API.md` (sección 04), `docs/EVENTS.md` (payloads 04), `docs/DATA_MODEL.md` (`monitoring.grants`). Migraciones `2026_11_06_000100`–`000400` roll-forward, aplicadas en pruebas y desarrollo con backup previo.
