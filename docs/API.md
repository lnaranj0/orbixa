# Contrato API inicial (propuesta)

Prefix `/api/v1`, sesión web protegida CSRF y API personal/service token con scopes, rate limits y revocación. Todos los endpoints bajo organización requieren contexto y autorización. Response 409 por versión obsoleta, 422 por validación, 403/404 para acceso denegado/ocultamiento de existencia.

| Método | Ruta | Función |
| --- | --- | --- |
| GET/POST | `/organizations` | Listar organizaciones propias / crear |
| GET/POST | `/organizations/{org}/teams` | Consultar / crear equipos |
| GET/POST | `/organizations/{org}/projects` | Listar / crear proyectos |
| GET/POST | `/organizations/{org}/projects/{project}/items` | Backlog / crear item |
| GET/PATCH | `/organizations/{org}/items/{item}` | Leer / editar item (con versión) |
| POST | `/organizations/{org}/items/{item}/transitions` | Transición validada |
| POST | `/organizations/{org}/items/{item}/assignments` | Asignaciones |
| GET/POST | `/organizations/{org}/projects/{project}/cycles` | Ciclos opcionales |
| GET | `/organizations/{org}/items/{item}/activity` | Actividad append-only |
| GET/POST | `/organizations/{org}/integrations/github` | Instalaciones/repo links |
| GET | `/organizations/{org}/spec-changes` | Índice de OpenSpec |
| POST | `/organizations/{org}/items/{item}/spec-links` | Vincular especificación |
| GET/POST | `/organizations/{org}/agents` | Catálogo agentes |
| POST | `/organizations/{org}/agents/{agent}/execution-reports` | Reporte externo idempotente, autenticación servicio |
| POST | `/webhooks/github` | GitHub App, autenticación HMAC, no token usuario |

## Ejemplo transición
```json
{ "to_status_id":"uuid", "reason":"Aprobado QA", "expected_version":12 }
```
Response incluye nueva versión, transición y correlation_id. Crear issue permite `Idempotency-Key` para reintentos de clientes; autorización por tenant resuelta del servidor, nunca confiando ciegamente en org_id del body.

## API de monitoreo de solo lectura
| Método | Ruta | Autorización |
| --- | --- | --- |
| GET | `/organizations/{org}/monitoring/overview` | `monitoring.view` + grant vigente + filtros scope |
| GET | `/organizations/{org}/monitoring/items` | Misma política en cada fila, paginación y búsqueda |
| GET | `/organizations/{org}/monitoring/teams/{team}` | Grant `team` que cubra ese equipo |
| GET | `/organizations/{org}/monitoring/users/{member}` | Grant `user` que cubra ese miembro |
| GET | `/organizations/{org}/monitoring/grants` | `monitoring.grants.view`; visores solo sus grants si se autoriza |
| POST | `/organizations/{org}/monitoring/grants` | `monitoring.grants.manage`, validar alcance y FK tenant |
| PATCH/DELETE | `/organizations/{org}/monitoring/grants/{grant}` | `monitoring.grants.manage`, cambio/revocación auditado |

`POST grants` acepta `viewer_membership_id`, `scope_type`, `target_membership_id` o `target_team_id` según corresponda, `include_descendants`, vigencia y motivo. Nunca acepta `organization_id` del body como fuente de autorización. Una consulta a recurso fuera de alcance responde 404/403 sin revelar existencia. No exponer filtros que permitan reconstruir datos fuera del grant mediante conteos o facetas.


## Requests / documentos / firma (v4)
| Método | Ruta | Finalidad |
| --- | --- | --- |
| GET/POST | `/organizations/{org}/requests` | Bandeja y captura de solicitudes |
| GET/PATCH | `/organizations/{org}/requests/{request}` | Detalle y edición con optimistic lock |
| POST | `/organizations/{org}/requests/{request}/transitions` | Transiciones auditadas |
| POST | `/organizations/{org}/requests/{request}/revisions` | Revisiones y cambios de alcance |
| POST | `/organizations/{org}/requests/{request}/work-links` | Vincular work items |
| POST | `/organizations/{org}/requests/{request}/documents` | Emitir snapshot + PDF versionado |
| GET | `/organizations/{org}/requests/{request}/documents/{version}/download` | Descargar documento autorizado |
| POST | `/organizations/{org}/requests/{request}/documents/{version}/signatures` | Registrar evidencia de firma/acuse |
| POST | `/organizations/{org}/requests/{request}/documents/{version}/decisions` | Aprobar o rechazar como firmante autorizado |
| POST | `/organizations/{org}/requests/{request}/delivery-acceptances` | Aceptar/devolver una entrega |

POST/PATCH con idempotency key y/o `expected_version`; autorizaciones independientes para PII y evidencia, no confiar en `organization_id` enviado en body. Descarga de binarios por stream autorizado o URL firmada de corta duración.

## Orbixa v5: rutas tentativas
`GET/POST /api/v1/projects/{project}/charter`; `GET/POST /api/v1/projects/{project}/deliverables`; `GET/POST /api/v1/projects/{project}/milestones`; `GET/POST /api/v1/projects/{project}/risks`; `GET/POST /api/v1/projects/{project}/decisions`; `GET/POST /api/v1/projects/{project}/baselines`; `GET/POST /api/v1/projects/{project}/budget`; `GET /api/v1/projects/{project}/progress`; `POST /api/v1/projects/{project}/close`. IDs resueltos siempre bajo contexto tenant; permisos específicos en endpoints financieros.
