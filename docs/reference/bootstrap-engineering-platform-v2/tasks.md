# Checklist maestro de implementación

> Secuencia dependiente. No ejecutar todas las fases en un único PR. Cada fase deberá desglosarse posteriormente en changes OpenSpec menores.

## Fase 0 — Base y controles
- [ ] 0.1 Crear repo, Laravel, calidad (Pint/PHPStan/Pest), CI, secretos por entorno, estrategia de ramas y PR.
- [ ] 0.2 Preparar PostgreSQL 18 aislado, rol runtime de privilegios mínimos, migraciones, seeds y rollback probado.
- [ ] 0.3 Crear módulos de dominio, IDs UUID, observabilidad/correlation_id y API versionada.
- [ ] 0.4 Levantar Redis, supervisión Horizon y monitoreo de jobs.
- [ ] 0.5 Implementar pipeline de pruebas de PostgreSQL 18 real y pruebas de seguridad multi-tenant.

## Fase 1 — Núcleo / MVP usable
- [ ] 1.1 Usuarios, organizaciones, membresías, invitaciones, contexto tenant y revocación de sesión/cache.
- [ ] 1.2 RBAC por organización/proyecto y políticas con casos permitidos/denegados.
- [ ] 1.3 Equipos, subequipos y miembros; validación de no-ciclos.
- [ ] 1.4 Portafolio, proyectos, tipos de items, clave secuencial por proyecto y relaciones jerárquicas.
- [ ] 1.5 Workflows, estados/transiciones, prioridades, etiquetas, comentarios y archivos adjuntos seguros.
- [ ] 1.6 Asignaciones humanas, dependencias, subtareas y detección de ciclos.
- [ ] 1.7 Backlog, Kanban responsive, filtros guardados, vista Mi trabajo y búsquedas paginadas.
- [ ] 1.8 Actividad append-only, transactional outbox, notificaciones internas básicas.
- [ ] 1.9 Pruebas E2E de ciclo completo, concurrencia, aislamiento y autorización; demo de MVP.

## Fase 2 — Planificación y GitHub/OpenSpec
- [ ] 2.1 Modos continuo/sprint/híbrido y ciclos con capacidad; sin forzar cycle_id.
- [ ] 2.2 GitHub App mínimo privilegio, instalación por tenant y selección de repos.
- [ ] 2.3 Webhook HMAC, inbox idempotente, colas, DLQ/replay y reconciliación programada.
- [ ] 2.4 Indexar PR, branches, commits, revisiones y enlaces explícitos por work item.
- [ ] 2.5 Indexar rutas OpenSpec, proposals/specs/design/tasks con commit/hash y errores de parser.
- [ ] 2.6 Vínculo work item ↔ change/spec/task, checklist y política de reconciliación de IDs.
- [ ] 2.7 Dashboards de lead/cycle time, WIP, throughput, aging y trazabilidad de entregas.
- [ ] 2.8 Pruebas con eventos duplicados, desordenados, fallidos y accesos GitHub revocados.

## Fase 3 — Agentes registrados / seguimiento
- [ ] 3.1 Catálogo tipado de actores humanos/IA, capabilities y política de roles.
- [ ] 3.2 Asignación de agentes, owner humano y restricciones por repositorio/proyecto.
- [ ] 3.3 API para importar ejecuciones externas con evidencias sanitizadas e idempotencia.
- [ ] 3.4 UI de ejecuciones, estados, costos opcionales, errores y trazabilidad de PR.
- [ ] 3.5 Confirmar mediante pruebas que la ejecución autónoma permanece deshabilitada.

## Fase 4 — Ejecución aprobada, automatizaciones y escala
- [ ] 4.1 Diseñar Agent Gateway aislado, runner efímero, políticas de herramientas, límites y secreto efímero.
- [ ] 4.2 Aprobación humana y guardrails para creación/escritura de PR y despliegues.
- [ ] 4.3 Reglas de automatización y notificaciones multicanal sin bucles.
- [ ] 4.4 Métricas avanzadas, planeación de capacidad, configuración por tenant y cuotas.
- [ ] 4.5 Pruebas de restauración, carga, observabilidad, retención y preparación de operación SaaS.

## Enmiendas obligatorias: PostgreSQL, modularidad y viewer
- [ ] 0.6 Migrar diseño/migraciones a PostgreSQL 18 (`uuid`, `jsonb`, `timestamptz`, lock_version), roles separados para migración/runtime y pruebas con PostgreSQL real.
- [ ] 0.7 Implementar y probar RLS forzada, contexto de tenant por transacción, pool de conexiones, jobs, exports y bypass.
- [ ] 0.8 Definir estructura modular, contratos públicos, ownership de tablas y verificador CI de dependencias cross-module.
- [ ] 1.10 Crear `access.monitoring_grants` y eventos auditados; validación de targets y miembros del mismo tenant, vigencia y revocación.
- [ ] 1.11 Implementar `MonitoringAccessPolicy` y query service centralizado para scopes usuario/equipo/organización y confidencialidad.
- [ ] 1.12 Construir UI read-only de monitoreo y gestión administrativa de grants, con filtros de subequipos y expiración.
- [ ] 1.13 Probar viewer en detalle, listas, reportes, adjuntos, API, exportación, cache, métricas, revocación y 2 organizaciones; no mutación.
- [ ] 2.9 Aplicar scopes viewer a vistas de metadatos GitHub/OpenSpec, ocultando nombres y datos confidenciales de repositorios no autorizados.
