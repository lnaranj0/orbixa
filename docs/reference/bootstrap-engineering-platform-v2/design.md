# Diseño técnico — Engineering Management Platform

## 1. Decisiones (ADR)
- ADR-001: monolito **siempre modular** Laravel; bounded contexts, contratos y eventos versionados, migraciones y tests por módulo; no microservicios iniciales.
- ADR-002: PostgreSQL 18 dedicado por entorno con `organization_id`, RLS obligatoria como defensa en profundidad y autorización por recurso en Laravel.
- ADR-003: usuarios globales + membresías y roles con scope organizacional o proyecto; `organization_id` nunca se acepta sin validar membresía.
- ADR-004: UUID nativos `uuid` de PostgreSQL; IDs externos GitHub separados (`bigint` o `text` según proveedor).
- ADR-005: PostgreSQL como verdad de negocio, Redis para cache/queues, Horizon para observabilidad de colas Redis.
- ADR-006: GitHub contiene código/OpenSpec; EMP guarda índices, hashes, referencias y métricas derivadas, no una segunda copia autoritativa de especificaciones.
- ADR-007: GitHub App y webhooks con HMAC, event inbox y workers; acción GitHub saliente requiere política explícita.
- ADR-008: workflow configurable, estados estables por ID y categoría semántica; cambios auditan actor y causa.
- ADR-009: identidad de agente separada de usuario humano; tareas pueden tener owner humano y agentes colaboradores.
- ADR-010: eventos de dominio persistentes mediante transactional outbox; event handlers idempotentes.

## 2. Descomposición de módulos
IdentityAndTenancy, Access, Portfolio, Teams, Work, Workflow, Planning, Specs, GitHubIntegration, Monitoring, Agents, Notifications, Audit, Reporting, Automation (futuro).
Cada módulo incluye Application / Domain / Infrastructure / Presentation / Contracts / Database / Tests; prohibido acceder directamente a repositorios de otro módulo sin interfaz o evento. Tests estáticos de dependencias y tests de contrato en CI.

## 3. Modelo multi-tenant
Toda consulta de recursos organizacionales usa `TenantContext` resuelto desde ruta o selección de organización y membresía autorizada. Route model binding con alcance contextual. Toda FK interorganizacional debe impedir asociaciones cruzadas: verificar en dominio y preferir claves únicas compuestas `organization_id,id` o restricciones equivalentes; complementar con FKs compuestas PostgreSQL `(organization_id, id)` cuando aplique. Jobs, comandos, exports, cache keys, búsquedas y eventos transportan tenant_id. Los logs deben minimizar datos sensibles. `platform_admin` no concede lectura automática de contenido de otros tenants: requiere acceso de soporte auditado y temporal.

## 4. Identidad/RBAC
Roles por organización/proyecto, invitaciones y estado de membresía; Spatie Permission puede implementarse con scopes explícitos y políticas Laravel, sin depender de un `team_id` global implícito. Prohibido tomar permisos de otro tenant por caché. Acciones sensibles requieren permisos más autorización contextual y, opcionalmente, aprobación.

## 5. Work item
Tipos: initiative, epic, feature, task, bug, chore, incident. Jerarquía mediante parent_id validado contra org/proyecto; impedir ciclos. Identificador legible `PROJECT-0001` con contador transaccional por proyecto. Estatus pertenecen a workflow por proyecto (category: backlog, ready, active, review, done, canceled); WIP límite opcional; cambios a done requieren validaciones configuradas. `cycle_id` opcional. Owner humano opcional según configuración, pero trabajo automatizado crítico requiere aprobador humano.

## 6. Integración GitHub
GitHub App por instalación; repositorios autorizados vinculados con project. Webhooks reciben firma SHA256, guardan raw payload en almacenamiento cifrado/retención limitada, `X-GitHub-Delivery` y estado, responden 2xx rápido y procesan en cola. La clave de idempotencia es instalación + delivery; procesador distingue evento/action/resource id. No inferir finalización de work item de `pull_request.closed` salvo merged=true y regla aprobada, y nunca inferir deployment de merge.
Flujo de enlace: formato preferido `EMP-123` en rama/PR/commit; asociaciones explícitas tienen prioridad. Sincronización por defecto unidireccional de metadatos externos hacia EMP; escribir en GitHub (comentarios/etiquetas/issues) requiere opt-in y prevención de loops por correlation/source id. Reconciliación mediante cursor + paginación respetando rate limit.

## 7. OpenSpec
Explorar rutas configurables de repositorio (por defecto `openspec/changes/*/proposal.md`, `design.md`, `tasks.md`, `specs/**/spec.md`, `openspec/specs/**`). Guardar vínculo con repo, ref, commit SHA, path, artifact kind, content hash y parse status. `tasks.md` checklist puede materializarse como `spec_task` con external stable key (path + item ordinal/normalized text hash + commit provenance); no usar ordinal como identidad definitiva ante reordenamiento. Mostrar diferencia/estado y solicitar reconciliación cuando cambie identidad. Nunca sobrescribir archivo Git desde web sin PR aprobado y con permisos explícitos.

## 8. Agentes
`agent` tiene organization_id, provider, external_ref, capabilities, enabled, policies. `work_actor` representa humano o agente con identidad inmutable tipada y assignments; no unir agentes a tablas users. Fase inicial: ejecutar no disponible, registrar `execution` proveniente de sistema externo con actor/estado/evidencia. Fase posterior: execution request aprobado, runner efímero, credenciales cortas, límites por repo, no secretos en prompts/logs, tool allowlist, review humano.

## 9. Eventos, consistencia y trazabilidad
Eventos versionados (`event_name`, `event_version`, `event_id`, tenant_id, actor, aggregate, occurred_at, correlation_id, causation_id, payload filtrado). Outbox transaccional y consumer dedupe (event_id/consumer); inbox para webhooks. Historial de estados append-only; tablas actuales para consultas. Retries con backoff y dead-letter/manual replay.

## 10. Operación y no funcionales
TLS, secrets manager o configuración externa cifrada, cifrado backup, mínimos privilegios SQL; zero trust entre servicios. Soportar zonas horarias por usuario y almacenar UTC. Índices `(organization_id, project_id, status_id)`, `(organization_id, assignee_actor_id)` y fechas para backlog; paginación cursor en feeds. Observabilidad: correlation IDs, colas, drift Git, errores de autorización, latency p95. Backups probados y restauración en ambiente aislado. Tests de integración PostgreSQL 18 real, ya que SQLite no reproduce restricciones y sintaxis. Tests de accesibilidad UI y concurrencia.

## 11. API
Versionar bajo `/api/v1`; contexto por `org_slug` o UUID de ruta. Respuestas paginadas, idempotency key para comandos externos críticos, optimistic concurrency con `lock_version`/ETag; rechazar ediciones sobre versiones obsoletas con 409. Webhooks separados de rutas de API autenticadas por usuarios.

## 12. Decisiones abiertas controladas
SSO/MFA de usuarios, política definitiva de retención, requisitos SLA/RPO/RTO, plan de upgrade PostgreSQL, volumen estimado, hosting de clientes externos y billing. No bloquean el modelo funcional, pero sí el endurecimiento productivo.

## 13. PostgreSQL y seguridad por fila
- PostgreSQL 18 dedicado por entorno; migraciones por módulo; `uuid`, `jsonb`, `timestamptz`, `lock_version bigint`. Índices de tenant y consultas reales medidos con `EXPLAIN (ANALYZE, BUFFERS)`.
- RLS y `FORCE ROW LEVEL SECURITY` en tablas del tenant, rol de runtime sin `BYPASSRLS`, rol de migración separado. Contexto por transacción con `set_config(..., true)`, sin permitir operaciones sobre tablas scoped fuera de transacción con tenant verificado; roles y jobs aplican la misma política.
- RLS protege principalmente el aislamiento organizacional; `MonitoringAccessPolicy` verifica el nivel usuario/equipo/organización, pertenencia, confidencialidad, permisos de exportación y vigencia. Todas las rutas de lectura pasan por un query service con filtros de acceso; no exponer repositorios del módulo Work directamente a la UI.
- Probar conexiones reutilizadas, cambio de tenant, SQL directo, colas, cache, exportaciones, adjuntos y datos archivados. Backup PITR con pruebas de restauración.

## 14. Módulo Monitoring y grants delegados
- Grant independiente del rol, administrable solo por `monitoring.grants.manage`; un viewer sin grant no ve recursos. Scopes `user`, `team`, `organization`, nunca `platform`.
- `user`: items con relación directa autorizada al usuario objetivo; `team`: items vinculados al equipo, `include_descendants` explícito; `organization`: recursos estándar del tenant. Restricción de proyectos confidenciales y campos sensibles se aplica incluso a scopes amplios.
- Normalizar en `Access/Contracts/MonitoringAuthorizer` y `Monitoring/Application/Queries`; filtros para búsquedas/listados/contadores deben usar una subconsulta SQL segura (no filtros en memoria después de paginar). `Monitoring` no muta `Work`. Grants combinados por unión, con precedencia de las restricciones de confidencialidad.
- Cambio de membresía, jerarquía de equipos, visibilidad de proyecto o grant invalida permisos efectivos; revocación se verifica en lectura aunque la caché no se haya refrescado. Auditoría de administración de grants y accesos sensibles.
- Primer release: reportes agregados de estado y progreso, listado filtrado y detalle autorizado. No exponer opiniones privadas, credenciales, tokens ni herramientas para inferir el trabajo de terceros por diferencia de conteos.
