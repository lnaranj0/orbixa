# Modelo de datos lógico PostgreSQL 18

Convención: claves UUID (`uuid`), instantes `timestamptz` en UTC, `organization_id uuid` en entidades tenant-scoped, contador `lock_version bigint` para optimistic concurrency. `UNIQUE(organization_id, ...)` donde corresponda. FKs coherentes con pertenencia a tenant y sin cascadas destructivas indiscriminadas.

| Esquema | Tablas (principales) |
| --- | --- |
| `identity` | users, auth_identities, organizations, organization_memberships, invitations, support_access_grants |
| `access` | roles, permissions, role_permissions, membership_roles, project_role_grants, monitoring_grants, service_principals |
| `teams` | teams (parent_team_id), team_members, team_parent_changes |
| `portfolio` | projects, project_members, project_team_links (02); project_repositories (06). `project_counters` pasa a 03 y los hitos a `12-project-management` |
| `work` | items, item_types, labels, item_labels, item_comments, item_attachments, item_assignments, item_dependencies, item_events |
| `workflow` | workflows, statuses, transitions, transition_guards, project_workflows |
| `planning` | cycles, cycle_items, cycle_capacity, saved_views |
| `specs` | changes, artifacts, spec_tasks, work_spec_links, spec_sync_runs |
| `integrations` | connections, github_installations, github_repositories, external_resources, resource_links, webhook_inbox, integration_sync_runs |
| `agents` | agents, agent_capabilities, agent_policies, executions, execution_events |
| `events` | domain_outbox, consumer_receipts, notifications, audit_log |

## Cardinalidades y restricciones
- user N:M organization mediante organization_memberships; N:M teams mediante team_members.
- organization 1:N projects, teams, workflows, connections y agents.
- team 1:N subteams, sin ciclos ni más de 5 niveles (trigger `teams.enforce_hierarchy()` con advisory lock por organización); team N:M project mediante `portfolio.project_team_links` (enlace directo, sin herencia).
- Miembros de equipo y proyecto, y enlaces, son filas temporales (`assigned/linked_*`, `removed/unlinked_*`) que conservan el historial; un cambio de rol cierra la fila y abre otra. Las inserciones toman `FOR SHARE` sobre el proyecto y el equipo destino, así un archivado concurrente espera (`2026_11_04_000700`).
- project 1:N items; item 0..1 parent y N:N dependencies; profundidad configurable.
- item N:M work actors usando assignment role (owner, implementer, reviewer, watcher). Owner humano puede ser único por item según regla.
- project 1:N cycles; item 0..N cycles históricos vía cycle_items, con máximo un ciclo activo planificado por item cuando política lo exige.
- repository 1:N specs changes, artifacts; change N:M items.
- integration 1:N external_resources, dedupe por provider+installation+external_id.

## Columnas críticas
`work.items`: id, organization_id, project_id, key, type_id, parent_id, workflow_status_id, title, description, priority, estimate, due_at, created_by, lock_version.
  - **Implementado en 03:** `type` texto con 7 valores (catálogo configurable más adelante), `status` como categoría global hasta que 05 añada `workflow_status_id`, `due_on date`, `number` + `key` (`<PROYECTO>-<n>`) asignados por `work.project_counters`. La FK al padre incluye `project_id` (mismo proyecto) y un trigger impide ciclos y más de 5 niveles.
`work.item_assignments`: id, organization_id, item_id, actor_type, actor_id, assignment_role, assigned_by, assigned_at.
  - **Implementado en 03:** solo `membership_id` (humano) con `role owner|collaborator`, filas temporales y un único owner activo; 08 añadirá `agent_id` con CHECK "exactamente uno". También `work.labels`/`item_labels` por proyecto, `item_team_links`, `item_dependencies` (mismo proyecto, sin ciclos, máximo 50) e `item_events` append-only. Evitar FK polimórfica sin guardas; alternativa recomendada tabla normalizada `work_actors(id, organization_id, type, user_id nullable, agent_id nullable)` con CHECK que solo uno está definido.
`integrations.webhook_inbox`: id, provider, installation_external_id, delivery_id, signature_valid, event_name, action, payload_location, received_at, processed_at, attempts, status, error_code; UNIQUE(provider,installation_external_id,delivery_id).
`events.domain_outbox`: event_id, organization_id, aggregate_type, aggregate_id, event_name, event_version, payload_json, occurred_at, published_at.
`specs.artifacts`: id, organization_id, repository_id, change_id nullable, path, ref, commit_sha, kind, content_hash, parse_status, indexed_at.

## PostgreSQL y operación
- PostgreSQL 18 como motor objetivo; Laravel `pgsql`, extensiones mínimas y política de upgrades. Validar compatibilidad en CI.
- Usar `uuid` y `timestamptz` (UTC), `jsonb` para metadatos y envelopes; índices GIN solo para consultas medidas. `lock_version bigint` para control optimista (no depender de columnas internas de PostgreSQL).
- Habilitar RLS para cada tabla tenant-scoped, con `FORCE ROW LEVEL SECURITY` donde aplique y roles de aplicación sin `BYPASSRLS` ni propiedad de tablas. La conexión de migración tiene rol distinto del runtime.
- Establecer tenant en transacción con `SET LOCAL`/`set_config('app.organization_id', ..., true)` y políticas con `current_setting('app.organization_id', true)` más cast seguro y denegación por defecto; prevenir fugas entre requests/conexiones de pool y jobs. El contexto por sí solo no sustituye las policies Laravel ni la autorización de grants.
- FKs compuestas con `organization_id` para relaciones tenant-scoped, evitar borrado cruzado y cifrar backups. Tests multi-tenant con **PostgreSQL real** (no SQLite).
- Índices por organization/project/status y organization/assignee; índices únicos tenant-scoped, paginación estable.
- Archivos y payloads grandes cifrados en object storage; SQL guarda metadatos y hashes.
- Cambios de tenant no se permiten para registros ya creados sin operación administrativa extraordinaria.

## Grants de monitoreo
`access.monitoring_grants`: `id uuid PK`, `organization_id uuid`, `viewer_membership_id uuid`, `scope_type enum lógico (user|team|organization)`, `target_user_membership_id uuid nullable`, `target_team_id uuid nullable`, `include_descendants boolean default false`, `visibility_level (standard|restricted) default standard`, `granted_by_membership_id uuid`, `starts_at timestamptz`, `expires_at timestamptz nullable`, `revoked_at timestamptz nullable`, `reason text`, `created_at timestamptz`, `lock_version bigint`. CHECK: exactamente un destino según `scope_type`; organisation sin target. FK compuestas para tenant y grants administrados dentro de la organización. Índices sobre `(organization_id,viewer_membership_id,revoked_at,expires_at)` y destinos.

`access.monitoring_grant_events` almacena altas, ediciones, expiraciones y revocaciones con actor/motivo/correlation_id. `work.item_team_links` y relaciones de asignación permiten calcular, de forma estable, visibilidad por usuario o equipo sin inferencias por nombres.

**Importante:** no basta RLS solo con `organization_id` para autorizar *subconjuntos* de lectura de un viewer; el servicio de visibilidad compone grant + membresía + confidencialidad + pertenencia a recursos. Si existen endpoints SQL directos se requieren views/funciones seguras o políticas adicionales que materialicen ese alcance. Evitar vistas `SECURITY DEFINER` sin control explícito.


## Nuevos esquemas/tables de Requests v4
| Esquema | Tablas nuevas |
| --- | --- |
| `requests` | request_types, requester_parties, development_requests, request_revisions, request_decisions, request_work_links, request_change_orders, request_events |
| `requirements` | document_templates, document_versions, document_signatories, document_signatures, document_attachments, delivery_acceptances |

`requests.development_requests`: id uuid, organization_id, folio, request_type_id, title, description, objective, business_reason, current_behavior, expected_behavior, scope_in, scope_out, acceptance_criteria jsonb, impact jsonb, requested_by_party_id, created_by_membership_id, submitted_at timestamptz, received_at timestamptz, status, confidentiality, lock_version bigint.
`requests.requester_parties`: id, organization_id, kind(member|external), membership_id nullable, verified_contact_method, name, area, position, email.
`requirements.document_versions`: id, organization_id, request_id, version_no, template_version, snapshot jsonb, pdf_storage_key, pdf_sha256, issued_at, status, immutable_at, UNIQUE(organization_id,request_id,version_no).
`requirements.document_signatures`: id, organization_id, document_version_id, signatory_id, mode(wet_ink|acknowledgement|external_e_signature), evidence_storage_key, evidence_sha256, signed_at, recorded_by, verification_status. No firma reutilizable entre versiones.
`requirements.delivery_acceptances`: id, organization_id, request_id, delivery_reference, decision, decided_at, decided_by, evidence_storage_key; independiente del documento de alcance.
Usar FK compuestas `(organization_id,id)` cuando proceda; RLS, índices `(organization_id,status,submitted_at)` y `(organization_id,requested_by_party_id)`. Archivos almacenados en storage privado; en BD solo referencias y hashes.

## Orbixa v5: Project Management
Consultar `12-project-management/design.md` para tablas `project_charters`, `project_scope_items`, `project_deliverables`, `project_milestones`, `project_schedule_baselines`, `project_dependencies`, `project_risks`, `project_issues`, `project_decisions`, `project_budgets`, `project_cost_entries`, `project_resource_allocations`, `project_progress_snapshots`, `project_closures`. Todas con UUID, `organization_id`, referencias tenant-safe y RLS. Reutilizar Project/Work existentes.
