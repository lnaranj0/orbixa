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

## Equipos y proyectos (02, implementado)
Todas las rutas cuelgan de `/api/v1/organizations/{organization}`: sesión + CSRF (grupo `web`) y membresía activa verificada en cada petición (middleware `organization`).

| Método | Ruta | Notas |
| --- | --- | --- |
| GET | `/teams` | Inventario según D15: `teams.manage` de organización ve todo; el resto, sus equipos y las ramas que lidera |
| POST | `/teams` | `{name, parent_team_id?}`; `Idempotency-Key` opcional |
| GET | `/teams/{team}` | 404 si no es visible |
| POST | `/teams/{team}/move` | `{parent_team_id: uuid\|null}` |
| POST | `/teams/{team}/archive` | 422 si tiene subequipos activos |
| POST / DELETE | `/teams/{team}/members[/{membership}]` | `{membership_id, role: member\|lead}` |
| GET | `/projects` | Filtros `status`, `type`, `confidentiality` aplicados después de la visibilidad |
| POST | `/projects` | `{key, name, type?, confidentiality?, description?}`; throttle 10/min por usuario; `Idempotency-Key` opcional |
| GET | `/projects/{project}` | Detalle; acceso a `restricted` por rol de organización queda auditado |
| PATCH | `/projects/{project}` | `{expected_version, name?, description?, type?, status?, confidentiality?}`; 409 si la versión cambió |
| POST / DELETE | `/projects/{project}/members[/{membership}]` | `{membership_id, role: member\|manager}` |
| POST / DELETE | `/projects/{project}/teams[/{team}]` | `{team_id}`; exige gestionar proyecto y equipo |

- **Paginación:** `limit` (1–100, por defecto 25) y `cursor` opaco firmado (HMAC), ligado al listado, la organización y los filtros. Respuesta `{data, next_cursor}` sin totales. Cursor editado o reutilizado con otros filtros: 422.
- **Idempotencia:** la clave (8–200 caracteres ASCII visibles) vale por usuario y operación durante 24 h. Reutilizarla con otro cuerpo: 422. Una repetición se reautoriza antes de devolver el cuerpo guardado.
- **Errores:** no visible, inexistente o de otra organización: 404 idéntico. Visible sin permiso: 403. Referencia inválida en el cuerpo (membresía o equipo inexistente, ajeno o archivado), validación o clave de proyecto ocupada: 422 genérico. Versión obsoleta: 409. Límite superado: 429.
- **Throttle general:** toda escritura usa `throttle:writes` (60/min por usuario).
- **Listados de proyectos:** owner/admin que no son miembros directos ven los `restricted` sin `description`; el detalle completo queda auditado.

## Trabajo (03, implementado)
Mismas reglas que 02: sesión + CSRF, membresía verificada por petición, `throttle:writes` en escrituras, cursores firmados sin totales, 404 idéntico para lo no visible, 403 visible sin permiso, 422 genérico para referencias inválidas, 409 para versión obsoleta. Un item es visible si y solo si su proyecto lo es.

| Método | Ruta | Notas |
| --- | --- | --- |
| GET | `/projects/{project}/items` | Backlog. `sort=number\|priority\|updated_at`; filtros `status`, `type`, `priority`, `label_id`, `parent_id`. Sin `description` |
| POST | `/projects/{project}/items` | `{type, title, description?, parent_id?, priority?, estimate?, due_on?}`; `Idempotency-Key` opcional (repetición reautorizada) |
| GET / POST | `/projects/{project}/labels` | Etiquetas del proyecto; crear exige `work.edit` sobre el proyecto |
| GET | `/me/work` | Asignaciones activas del usuario en proyectos que aún ve |
| GET | `/items/{item}` · `/items/by-key/{KEY-n}` | Detalle con etiquetas, asignaciones, equipos y dependencias. En `restricted` por rol de organización queda auditado (`work.view`) |
| PATCH | `/items/{item}` | `{expected_version, type?, parent_id?, title?, description?, priority?, estimate?, due_on?}` |
| POST | `/items/{item}/transitions` | `{expected_version, to, reason?}`; flujo base de `WORKFLOWS.md`; motivo obligatorio para cancelar, reabrir y restaurar |
| GET | `/items/{item}/activity` | Actividad append-only paginada; la descripción solo como `{changed, length}` |
| POST / DELETE | `/items/{item}/assignments[/{membership}]` | `{membership_id, role: owner\|collaborator}`; nadie se asigna a sí mismo; asignado sin acceso al proyecto: 422 |
| POST / DELETE | `/items/{item}/labels[/{label}]` | Solo etiquetas del mismo proyecto; máximo 20 |
| POST / DELETE | `/items/{item}/teams[/{team}]` | Equipo activo; no da visibilidad |
| POST / DELETE | `/items/{item}/dependencies[/{blocker}]` | Mismo proyecto, sin ciclos, máximo 50 |

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
