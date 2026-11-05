# Diseño: 06-github-integration

Decisiones G-A…G-F y G1–G12 aprobadas el 2026-11-04. Las propusieron los agentes `architect` y `security-reviewer`. El cambio se entrega en dos PR: **06a** (incrementos 1–6: base, enrutamiento, webhook e instalación) y **06b** (incrementos 7–11: proyección, vínculos, revocación, reconciliación, API y pantallas).

## Contexto y decisiones
- Base de datos: PostgreSQL 18, `uuid` (uuidv7), `timestamptz`, `jsonb` solo para metadatos no relacionales.
- Arquitectura: monolito modular con capas `Domain`, `Application`, `Infrastructure`, `Presentation`, `Contracts`, `Database/Migrations`, `Tests`.
- Todo registro de una organización lleva `organization_id`, sigue la plantilla RLS de 00 y usa FK compuestas. Las migraciones son roll-forward y su `down()` lanza excepción.
- Eventos por outbox con payload de solo IDs. Hasta ahora Orbixa no tenía ninguna entrada sin un usuario humano autenticado: 06 añade la primera, y es la pieza más sensible del cambio.

## Discrepancias resueltas
- **G-D · Vínculo repositorio→proyecto.** `DATA_MODEL.md` lo ubicaba en Portfolio (`project_repositories`). Lo posee GitHubIntegration (`integrations.repository_project_links`, con FK compuesta a `portfolio.projects`), porque Portfolio no debe saber de GitHub. Se corrige `DATA_MODEL.md`.
- **G-E · Sin cierre implícito.** El escenario de la spec admitía cerrar un item "con regla explícita". En 06 **nunca** se cambia el estado de un item desde GitHub: "Closes/Fixes KEY-n" se ignora y un PR fusionado solo deja metadatos y un evento.
- **G-F · Visores.** Los visores de monitoreo no ven metadatos de código en 06. El panel "Desarrollo" del item solo es visible para quien ve el proyecto.
- **Nombres.** El esquema es `integrations` y el módulo, `GitHubIntegration`. La spec usaba "referencia EMP" y "owner del tenant": la referencia real es la clave `KEY-n` de 03 y el permiso es `integrations.github.manage`.
- **Catálogo de eventos.** Faltaba un evento de revocación: se añade `github.installation.revoked`.

## Enrutamiento de webhooks sin contexto (G-A, opción 1)
- Los webhooks llegan sin sesión ni organización. La organización se resuelve con una **4.ª función `SECURITY DEFINER` revisada**:
  - `integrations.organization_for_installation(bigint) → uuid`;
  - propiedad de un rol `NOLOGIN` nuevo (`orbixa_installation_reader`), con una policy nominal de solo `SELECT` sobre las instalaciones activas;
  - `search_path` fijo y `EXECUTE` revocado a `PUBLIC`, concedido solo al runtime.
  - Es el mismo patrón que `identity.has_active_membership` en 01.
- Devuelve solo el `organization_id` de una instalación **activa**, o `null`. No expone ninguna otra columna ni permite listar.
- El rol se crea con el script de provisión (lo ejecuta una persona, como el resto de roles). `RlsCatalogTest` se actualiza con la función y la policy, que quedan revisadas de forma explícita.
- Se descarta una credencial de BD aparte, porque la app web no debe manejar otra, y una tabla global con `organization_id`, porque quedaría fuera de RLS.

## Contexto de tenant sin humano (G-B)
- Nueva entrada en el contrato de Platform: `TenantContext::runForIntegration(OrganizationId, IntegrationPrincipal, callback)`.
  - Dentro de la transacción vuelve a comprobar, mediante la función de enrutamiento, que la instalación sigue activa y pertenece a esa organización. Después fija el GUC con `set_config(..., true)`, igual que `runFor`.
  - Nunca acepta una organización que venga del payload.
- `IntegrationActor` no es un `User`. Sus permisos son fijos: proyectar metadatos y crear o cerrar vínculos. No transiciona, no asigna ni edita items. En el outbox aparece como `actor_type = service`, `actor_id = integration:github:<installation>`.
- Los jobs llevan solo IDs (organización y fila del inbox), y al ejecutarse vuelven a resolver la instalación.

## Instalación (G-C, opción b)
- La vinculación se inicia desde Orbixa con `integrations.github.manage`. Se genera un `state` firmado, de un solo uso y ligado a la sesión y a la organización.
- En el callback (`setup_url`) el `installation_id` de la URL **no es de fiar**. Se confirma:
  1. con el JWT de la App (`GET /app/installations/{id}`), que la instalación existe;
  2. con la autorización OAuth de usuario de la App (user-to-server), que quien vincula tiene acceso real a esa instalación (`GET /user/installations`).
- `UNIQUE (installation_external_id)` global: una instalación pertenece a una sola organización. Un segundo intento desde otra organización recibe un error genérico.
- Los repositorios llegan como "disponibles" y solo se indexan los que una persona con `integrations.github.manage` **selecciona** de forma explícita. Se identifican por su id numérico, nunca por el nombre, que cambia al renombrar o transferir.

## Webhook (G3)
- Ruta `POST /webhooks/github`, sin sesión, CSRF ni `auth`, con throttle por IP.
- El tamaño se comprueba con la cabecera antes de leer el cuerpo, y la lectura se corta en el límite: por encima, 413. El límite real se fija también en nginx (`client_max_body_size` para `/webhooks/github`) y en `post_max_size`.
- La firma `X-Hub-Signature-256` se verifica sobre los bytes crudos con `hash_equals` **antes** de que Orbixa interprete el payload. Se acepta el secreto actual y el anterior, para poder rotarlo. Firma ausente, mal formada o incorrecta: 401 sin detalles.
  - Laravel decodifica una vez el JSON al capturar cualquier petición (`Request::createFromBase`), antes de los middleware. Se acepta (decisión del 2026-11-05): profundidad 512, falla en silencio y el tamaño queda limitado por nginx. La ruta se excluye de `TrimStrings` y `ConvertEmptyStringsToNull` para evitar su recorrido recursivo.
- El throttle es por IP. De momento hay solo nginx local, sin balanceador: si se añade uno, hay que configurar `trustProxies` con sus IPs antes de exponer la ruta.
- Deduplicación en un registro **global** `integrations.webhook_deliveries` sin payload, organización ni instalación (el id de instalación identifica a una organización): `delivery_id` único, hash del cuerpo único, evento y resultado. El runtime solo tiene `INSERT` (`ON CONFLICT DO NOTHING`), no lo puede leer.
  - El registro y la fila del inbox se escriben en la misma transacción.
  - Las cabeceras de evento y de entrega no van firmadas: el registro es la protección anti-replay y **no se purga** por debajo de esa ventana.
  - Una entrega ignorada (antes de vincular la instalación o seleccionar el repositorio) será `duplicate` si GitHub la reenvía; esos cambios los recupera la reconciliación.
  - Un payload que `jsonb` no admite (`\u0000`) se registra como ignorado. Los fallos de BD se reportan solo con el SQLSTATE, nunca con el cuerpo.
  - Mismo `delivery_id`: 200.
  - Mismo cuerpo con un `delivery_id` nuevo: 200.
  - Además, idempotencia de dominio con `last_synced_version`.
- Si la instalación está enrutada y el repositorio seleccionado, el payload se guarda en el inbox **por organización** (`integrations.webhook_inbox`, RLS, retención de 30 días) y se despacha el job. En otro caso responde 202 `ignored` sin guardar el payload.
- Respuesta en menos de 10 s: sin trabajo pesado en la petición.

## Modelo de datos (G1)
- Por organización, con RLS: `github_installations`, `github_repositories`, `repository_project_links`, `external_resources`, `resource_links`, `webhook_inbox` y `sync_runs`.
- Global, en la lista blanca de `RlsCatalogTest`: `webhook_deliveries`, que solo deduplica y no guarda datos.
- Datos mínimos de PR, commit y check: número, título, estado, sha, nombre del check y `html_url` con host `github.com`. Sin diff ni cuerpo completo.

## Vínculos con items (G6)
- Las claves `KEY-n` se extraen de título, rama y cuerpo con una regex acotada, como máximo 20 por evento.
- **Solo se vinculan items de proyectos ligados a ese repositorio.** Nunca se busca la clave en toda la organización, así que un PR no puede apuntar a un item ajeno ni a un proyecto `restricted` no mapeado.
- Puerto `Work\Contracts\Integration\ItemReferenceResolver` en una capa deptrac propia (`WorkIntegrationContracts`), que solo usa GitHubIntegration.
- Work no se modifica: un test comprueba que ni el estado ni `lock_version` del item cambian.

## Orden, reconciliación y saliente (G7–G9)
- **Orden.** Un cambio solo se aplica si su versión es más nueva que `last_synced_version`. Los upserts van por clave natural.
- **Reconciliación.** Paginada por cursor, con backoff exponencial con tope y respetando `Retry-After` y `X-RateLimit-*`. Una ejecución activa por repositorio. Hay un botón manual con `integrations.github.manage` y throttle. El `Link` de paginación debe apuntar al mismo host.
- **Escritura hacia GitHub.** No se implementa ningún escritor. La App pide solo permisos de lectura (metadata, contents, pull_requests, checks). Un test verifica que no hay POST, PATCH, PUT ni DELETE, salvo la emisión de tokens.

## Secretos (G10)
- La clave privada de la App, el secreto del webhook y el secreto OAuth van en `.secrets/` (permisos 600, fuera de Git) o en el gestor del entorno. **Nunca** en la BD, Git, logs, payloads de jobs, vistas, excepciones ni respuestas.
- Los tokens de instalación (1 h) se cachean en Redis cifrados, con TTL menor que su caducidad.
- `error_code` guarda solo la clase de la excepción, y la cabecera `Authorization` se redacta.
- Un test escanea logs, `failed_jobs` y respuestas en busca de los secretos.
- Sin App real en desarrollo: GitHub queda detrás del puerto `GitHubApi`, probado con un fake y fixtures firmadas.

## Eventos (G11)
`github.installation.connected`, `github.installation.revoked`, `github.repository.linked`, `github.pull_request.updated`, `github.commit.linked` y `github.sync.failed`, con solo IDs y `correlation_id` igual al `delivery_id`. Revocar o suspender una instalación detiene la indexación; los vínculos se conservan como historial.

## API y pantallas (G12)
- Bajo `/api/v1/organizations/{organization}/integrations/github`: setup, repositorios (seleccionar o retirar), vínculos a proyecto, `reconcile` y `sync-runs`, con `integrations.github.manage`.
- `GET /items/{item}/development`, visible para quien ve el proyecto.
- Pantallas: la página de la integración (estado, repositorios, último sync y errores sin secretos) y el panel "Desarrollo" en el item.

## Incrementos
**06a**
1. Diseño y spec.
2. Esquema `integrations`.
3. Función de enrutamiento, rol `NOLOGIN` y `runForIntegration`.
4. Puerto `GitHubApi`, secretos y tokens.
5. Webhook.
6. Instalación con OAuth y selección de repositorios.

**06b**
7. Proyección y vínculos.
8. Revocación y suspensión.
9. Reconciliación.
10. Test de que no hay escritura hacia GitHub.
11. API, pantallas, revisión de seguridad, documentación y CI.

## Riesgos y decisiones pendientes
- G-A y G-B amplían la superficie entre organizaciones: hace falta revisión explícita y pruebas con el rol runtime real.
- El `UNIQUE` global de la instalación delata que existe en otra organización; se responde con un error genérico.
- Sin App real, la compatibilidad con GitHub solo queda validada con fixtures, hasta una prueba manual con una App de desarrollo.
- Los payloads de repos privados exigen retención mínima (30 días) y minimización.

## Verificación de salida
Un PR se vincula con una tarea sin cerrarla automáticamente; duplicados no producen doble efecto y secretos no son visibles.
