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
- `organization.created`, `organization.member.invited`, `organization.member.revoked`.
- `team.created`, `team.member.assigned`, `project.created`, `project.repository.linked`.
- `work.item.created`, `work.item.updated`, `work.item.assigned`, `work.item.transitioned`, `work.item.blocked`, `work.item.completed`, `work.item.reopened`.
- `planning.cycle.created`, `planning.cycle.started`, `planning.cycle.closed`.
- `github.installation.connected`, `github.repository.linked`, `github.pull_request.updated`, `github.commit.linked`, `github.sync.failed`.
- `spec.change.detected`, `spec.artifact.indexed`, `spec.change.linked`, `spec.reconciliation.required`.
- `agent.registered`, `agent.assigned`, `agent.execution.reported`, `agent.execution.failed`.
- `audit.sensitive_access.granted`, `notification.requested`.

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
