# Contratos de eventos, webhooks y automatización

## Envoltorio común v1
```json
{
  "event_id": "uuid",
  "event_name": "work.item.created",
  "event_version": 1,
  "organization_id": "uuid",
  "aggregate_type": "work_item",
  "aggregate_id": "uuid",
  "actor": {"type":"human","id":"uuid"},
  "correlation_id": "uuid",
  "causation_id": null,
  "occurred_at": "2026-11-03T17:00:00Z",
  "payload": {"project_id":"uuid","work_item_key":"EMP-123"}
}
```
Los eventos transportan IDs y metadatos mínimos, no credenciales ni texto sensible. `event_version` es contrato independiente de versión API.

## Eventos de negocio
- `organization.created`, `organization.member.invited`, `organization.member.joined`, `organization.member.role_changed`, `organization.member.revoked`. Sin email ni nombre en el payload: solo IDs de organización, membresía, rol y actor.
- `team.created`, `team.moved`, `team.archived`, `team.member.assigned`, `team.member.removed`, `project.created`, `project.updated`, `project.member.added`, `project.member.removed`, `project.team.linked`, `project.team.unlinked`, `project.repository.linked`. Payload con solo IDs.
- `work.item.created`, `work.item.updated`, `work.item.assigned`, `work.item.transitioned`, `work.item.blocked`, `work.item.completed`, `work.item.reopened`.
- `planning.cycle.created`, `planning.cycle.started`, `planning.cycle.closed`.
- `github.installation.connected`, `github.repository.linked`, `github.pull_request.updated`, `github.commit.linked`, `github.sync.failed`.
- `spec.change.detected`, `spec.artifact.indexed`, `spec.change.linked`, `spec.reconciliation.required`.
- `agent.registered`, `agent.assigned`, `agent.execution.reported`, `agent.execution.failed`.
- `audit.sensitive_access.granted`, `notification.requested`.

### Payloads de 02 (versión 1)
| Evento | Agregado | Payload |
| --- | --- | --- |
| `team.created` | team | `parent_team_id` |
| `team.moved` | team | ids del padre anterior y nuevo |
| `team.member.assigned` / `team.member.removed` | team | `membership_id`, `role` (solo en assigned) |
| `project.created` | project | `type`, `confidentiality` (el creador queda como manager sin evento aparte) |
| `project.updated` | project | `fields` cambiados; `confidentiality {from, to}` si cambió. Nunca textos |
| `project.member.added` / `project.member.removed` | project | `membership_id`, `role` (solo en added). Un cambio de rol emite removed + added |
| `project.team.linked` / `project.team.unlinked` | project | `team_id` |
| `audit.sensitive_access.granted` | project | `reason: organization_role`, `operation: view\|update`, `viewer_membership_id`. Uno por actor, proyecto y operación cada 10 minutos |

## Webhooks GitHub MVP
`installation`, `installation_repositories`, `repository`, `pull_request`, `pull_request_review`, `push`; incluir `issues`/`issue_comment` solo si se habilita sincronización de issues. Firma HMAC SHA-256 sobre bytes originales, comparación temporalmente segura, secret rotatable. Delivery GUID + instalación + event/action: dedup y trazabilidad. Si verificación falla retornar 401; si el evento es válido pero no seleccionado, 2xx con estado ignored. Responder en menos de 10 segundos tras persistir inbox, sin procesamiento pesado síncrono.

## Reglas y bucles
`source_system`, `source_event_id`, `correlation_id` y `last_synced_version` para deduplicación. Primer release: GitHub -> EMP metadatos; EMP -> GitHub deshabilitado por defecto. Enviar señales outbound solo mediante allowlist y cada regla con su own idempotency key. Backoff exponencial, DLQ, reintento manual y reconciliación periódica.

## Catálogo inicial de notificaciones
Asignación, mención, revisión solicitada, bloqueo, comentario importante, entrega, PR listo para revisar, OpenSpec cambió, error de sincronización. Preferencias y quiet hours por usuario.

## Eventos de monitoreo
- `monitoring.grant.created`, `monitoring.grant.updated`, `monitoring.grant.revoked`, `monitoring.grant.expired`, `monitoring.viewer.access_denied` (muestreado, sin revelar datos privados).
- Payload mínimo: `grant_id`, `organization_id`, `viewer_membership_id`, `scope_type`, `target_id` permitido, `granted_by`, `expires_at`, `correlation_id`; prohibido incluir datos completos de tareas que no correspondan al alcance.
- Los consumidores de notificaciones, búsquedas y reportes deben revalidar autorización al leer/entregar, nunca confiar solamente en grants vigentes cuando se encoló el evento.


## Eventos Requests/RequirementDocuments v4
`request.created`, `request.submitted`, `request.clarification_requested`, `request.specified`, `request.revision.created`, `request.approval.requested`, `request.approved`, `request.rejected`, `request.work_item.linked`, `request.delivered`, `request.delivery.accepted`, `request.delivery.returned`, `requirement.document.issued`, `requirement.document.signature_recorded`, `requirement.document.superseded`.
En payload solo IDs, número de versión, hash del documento y estados; no nombre/correo del firmante ni contenido del requerimiento. Publicación transaccional por outbox e idempotencia por evento/versión. No enviar documentos confidenciales en notificaciones; usar enlaces autenticados con expiración y autorización en descarga.

## Orbixa v5: eventos de Project Management
`ProjectCharterApproved`, `ProjectBaselineApproved`, `ProjectRiskRaised`, `ProjectMilestoneCompleted`, `ProjectScopeChangeRequested`, `ProjectClosed`. Emitir mediante outbox transaccional con `organization_id`, `actor_id`, `correlation_id`, versión y clave de idempotencia.
