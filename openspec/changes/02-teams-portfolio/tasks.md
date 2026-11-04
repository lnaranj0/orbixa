# Tareas: 02-teams-portfolio

> Mantener tareas sin marcar hasta contar con evidencias de código, CI y pruebas.

- [x] 1. Crear teams con parent_id opcional, membership de miembros, validación anticíclos y jerarquías.
  - Evidencia:
    - `TeamsSchemaTest`: ciclos, profundidad 5, padre ajeno igual que inexistente, movimientos opuestos concurrentes con un solo ganador.
    - `TeamsUseCasesTest`: historial de padres y miembros, eventos sin datos personales, ascenso a líder con historial, revocación que cierra membresías.
    - `TeamLeadScopeTest` y `SecurityReviewTest` (H1): el líder gestiona su rama, nunca padre ni hermanos, ni designa, degrada o quita líderes.
- [x] 2. Crear proyectos con clave única por organización, miembros, relaciones a equipos y clasificación.
  - Evidencia:
    - `PortfolioSchemaTest`: formato e inmutabilidad de la clave, unicidad concurrente, historial, proyectos archivados.
    - `ProjectMembershipTest`: sin autoasignación, managers solo con permiso de organización, cambio de rol con historial, enlace que exige gestionar ambos lados.
    - `SecurityReviewTest` (B1, B2): archivar espera a un enlace concurrente; revocar o degradar al actor espera a su operación.
- [x] 3. Implementar políticas de consulta y gestión de equipos/proyectos, sin visibilidad implícita por simple pertenencia.
  - Evidencia:
    - `ProjectVisibilityTest`: matriz owner/admin/miembro directo/equipo enlazado/subequipo/viewer/otra organización; 403 frente a 404; 409; auditoría de restringidos.
    - `SecurityReviewTest` (M1, M2): listados sin `description` de restringidos ajenos, auditoría deduplicada, bajar confidencialidad solo a nivel de organización.
- [x] 4. Exponer APIs paginadas, filtros y pruebas de jerarquía y permisos.
  - Evidencia:
    - `PortfolioApiTest`: escenario "Paginación y filtros" (3 de 5), cursor firmado, 404 idéntico, 403/409/422, idempotencia por usuario y reautorizada, throttle de creación y de escrituras, inventario D15, entradas inválidas en 422.
    - `ScreensTest`: pantallas mínimas (D18) con las mismas reglas.

## Gates de entrega
- [x] Pruebas de aislamiento entre dos organizaciones sobre PostgreSQL real.
  - Evidencia: suite completa (241 tests) contra `postgres-test` como `orbixa_app`, sin BYPASSRLS.
- [x] Pruebas de permisos permitidos/denegados para roles y alcances aplicables.
  - Evidencia: `TeamLeadScopeTest`, `ProjectVisibilityTest`, `ProjectMembershipTest`, `SecurityReviewTest` y `PortfolioApiTest`.
- [x] Revisión automática de límites modulares en CI y contratos de integración.
  - Evidencia: CI del PR #3 (run 41883951670) en verde con PHP 8.4 y 8.5 sobre PostgreSQL 18: Pint, PHPStan, deptrac sin violaciones y suite completa como `orbixa_app`.
- [x] Documentar migraciones, rollback, cambios de API y evidencia de funcionamiento.
  - Evidencia: `docs/API.md` (sección 02), `docs/EVENTS.md` (payloads 02), `docs/DATA_MODEL.md`. Migraciones `000500`–`000700` roll-forward (`down()` lanza excepción), aplicadas en pruebas y desarrollo con backup previo.
