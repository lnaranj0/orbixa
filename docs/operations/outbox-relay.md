# Relay de la outbox: diseño

**Estado:** **opción A aprobada el 2026-11-03 e implementada.** La implementación está en `2026_11_03_000400_grant_outbox_relay_access`, `OutboxRelay`, `orbixa:outbox:relay` (programado cada minuto, `withoutOverlapping`, `onOneServer`), `DeliverDomainEvent` y `DomainEventPublished`. La verifican `RelayRolePrivilegesTest`, `OutboxRelayTest` y `RlsCatalogTest`, que admite solo las dos policies nominales del relay.

`orbixa_app` sigue pudiendo solo **añadir** eventos (SELECT e INSERT dentro del tenant activo).

## Problema

El relay debe leer los eventos pendientes de **todas** las organizaciones, publicarlos (cola Redis) y marcar `published_at`, `attempts` y `last_error`. RLS con FORCE impide hacerlo con `orbixa_app`, y conceder BYPASSRLS de forma amplia anula el aislamiento.

## Opciones

| | A. Rol `orbixa_relay` dedicado | B. Iterar por organización | C. Función `SECURITY DEFINER` |
| --- | --- | --- | --- |
| Mecanismo | Rol LOGIN sin BYPASSRLS. Una policy adicional `TO orbixa_relay USING (published_at IS NULL)` solo sobre `events.domain_outbox`. GRANT `SELECT` y `UPDATE (published_at, attempts, last_error, available_at)` solo en esa tabla. | `orbixa_app` recorre la lista de organizaciones con trabajo pendiente y abre `TenantContext::runFor()` por cada una. | Una función de la que es dueño el migrador, con `search_path` fijo, que devuelve un lote y marca la publicación. |
| Alcance entre tenants | Solo filas de la outbox: nada de datos de negocio ni de otras tablas. | Ninguno. Es la opción más pura. | Depende de que el cuerpo de la función sea correcto. |
| Requiere | Separar la policy por rol. Cambiar `RlsCatalogTest`, que hoy exige una sola policy, para permitir una lista revisada de excepciones. | Saber qué organizaciones tienen pendientes sin leer entre tenants: una tabla global de "pendientes por org" o un índice expuesto vía `SECURITY DEFINER`. Una petición a la BD por org. | Aceptar `SECURITY DEFINER`, que hoy prohíbe `RlsCatalogTest`. |
| Coste | Bajo. Un único `FOR UPDATE SKIP LOCKED` sirve para todas las orgs. | Alto con muchas organizaciones. | Bajo. |
| Riesgo | Si se filtra la credencial del relay, se exponen los payloads de la outbox. Mitigación: los payloads solo llevan IDs (docs/EVENTS.md). | El mismo hueco de la lista de pendientes. | Es fácil equivocarse. Rompe la regla "sin SECURITY DEFINER". |

## Recomendación

**Opción A**, con estas salvaguardas:
1. `orbixa_relay` sin BYPASSRLS, sin CREATE y sin membresías. Una credencial y un proceso dedicados (el worker del relay); nunca la app web.
2. La policy del relay solo concede SELECT y UPDATE de columnas de publicación. Un trigger impide modificar el resto de columnas.
3. `RlsCatalogTest` admite una sola excepción nominal (`events.domain_outbox` / `orbixa_relay`), con su expresión canónica.
4. Nuevas pruebas:
   - el relay no puede leer ninguna otra tabla;
   - no puede borrar;
   - no puede cambiar `payload` ni `organization_id`;
   - entrega al menos una vez con backoff;
   - los consumidores deduplican con `events.consumer_receipts`.
5. Los consumidores revalidan la autorización al procesar (docs/EVENTS.md). Nunca confían en el evento como permiso.

## Decisión

**A**, aprobada por el responsable del producto el 2026-11-03.

Implementación concreta:
- **Policies:**
  - `relay_read`: `FOR SELECT TO orbixa_relay USING (true)`. PostgreSQL también valida la **fila nueva** de un `UPDATE … WHERE` contra las policies de SELECT, así que una lectura limitada a pendientes impediría marcarlos como publicados (lo detectó `OutboxRelayTest`). Los pendientes ya eran visibles entre organizaciones y el histórico contiene la misma clase de datos (IDs y metadatos).
  - `relay_mark_published`: `FOR UPDATE TO orbixa_relay USING (published_at IS NULL) WITH CHECK (true)`.
- **Grants:** `SELECT` y `UPDATE (published_at, attempts, last_error, available_at)`. Al ser privilegios por columna, el relay no puede tocar `payload` ni `organization_id`, y no necesita un trigger adicional.
- **Fallos:** se guarda solo la clase de la excepción, nunca el mensaje. Backoff exponencial `2^(attempts+1)` s con tope de 1 h.
- **Entrega:** `DeliverDomainEvent` (5 intentos) dispara `DomainEventPublished` con el sobre v1 y restaura el `correlation_id` original.
- **Consumidores:** el helper de deduplicación con `events.consumer_receipts` llega con el primer consumidor real (`03-work-backlog`), junto con la identidad de servicio para procesar sin actor humano (`01-identity-tenancy`).
