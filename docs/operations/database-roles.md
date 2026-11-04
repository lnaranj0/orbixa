# Roles de base de datos, aislamiento y entorno de pruebas

Parte de `00-platform-foundation`: tareas 2 y 3, controles previos. Ninguna tarea se marca como completa sin evidencia de pruebas.

## Roles

| Rol | Atributos | Uso |
| --- | --- | --- |
| `orbixa_bootstrap` | Superusuario creado por la imagen Docker. No se puede degradar. | Solo aprovisionamiento con `scripts/db/provision.sh`. **Nunca** aparece en el `.env` de Laravel. |
| `orbixa_migrator` | `LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOINHERIT` | Dueño de esquemas, tablas y funciones. Ejecuta `migrate --database=pgsql_migrator`. Tiene `CONNECT` y `CREATE` sobre su base. No es dueño de la base. |
| `orbixa_app` | Mismos atributos. Sin membresía en el migrador. | Runtime de Laravel (conexión `pgsql`). Solo DML concedido tabla por tabla. Sin `TEMP`, `TRUNCATE`, `REFERENCES` ni `TRIGGER`. Sin acceso a `migrations`. |
| `orbixa_relay` | Mismos atributos. Sin membresías. | Relay de la outbox (conexión `pgsql_relay`, opción A). Lee `events.domain_outbox` y solo modifica filas **pendientes**, en las columnas de publicación. Ninguna otra tabla. Ver `outbox-relay.md`. |

Los roles son de todo el clúster. Por eso las pruebas usan un **servidor aparte** (`postgres-test`) y nunca la base de desarrollo.

## Credenciales separadas

| Archivo (gitignored) | Contenido | Quién lo lee |
| --- | --- | --- |
| `.secrets/pg_bootstrap_password` | Contraseña de bootstrap del servidor de desarrollo | Solo Docker Compose (secret) |
| `.secrets/pg_test_bootstrap_password` | Contraseña de bootstrap del servidor de pruebas | Solo Docker Compose (secret) |
| `.secrets/redis_password` | Contraseña de Redis | Docker Compose. Laravel lee `REDIS_PASSWORD` desde `.env`. |
| `.env` | `DB_*` del runtime y `DB_MIGRATOR_*` | Laravel |
| `.env.testing` | Lo mismo para el servidor de pruebas | Laravel (APP_ENV=testing) |

Crear los secretos con permisos `600`. Después de crear los roles, retirar `POSTGRES_ADMIN_PASSWORD` del `.env` de Laravel.

Las contraseñas de `orbixa_migrator` y `orbixa_app` nunca viajan en claro hacia PostgreSQL. `provision.sh` calcula en el cliente el verificador SCRAM-SHA-256 (`scripts/db/scram-verifier.php`) y `01_roles.sql` rechaza cualquier valor que no sea un verificador. La sesión de aprovisionamiento desactiva además `log_statement` y `log_min_error_statement`.

La conexión `pgsql` no acepta `DB_URL`, así que no se pueden sustituir sus credenciales en silencio. `php artisan orbixa:db:verify [--with-migrator]` comprueba en solo lectura, en despliegues o health checks, que el runtime no sea superusuario, no tenga BYPASSRLS y no sea miembro del migrador.

## Bloqueos contra modificar la base de desarrollo

1. `phpunit.xml` fuerza `DB_HOST=127.0.0.1`, `DB_PORT=5434` y `DB_DATABASE=orbixa_test`.
2. `SecurityTestCase` y `orbixa:test-db:reset` exigen el centinela `COMMENT ON DATABASE orbixa_test IS 'orbixa:ephemeral-test'`. Solo lo pone `provision.sh test sentinel`.
3. `MigrationConnectionGuard` aborta `migrate*`, `db:seed`, `db:wipe` y `schema:dump` en estos casos:
   - el comando no usa `--database=pgsql_migrator`;
   - el rol no es el migrador, o es superusuario o tiene BYPASSRLS;
   - con `APP_ENV=testing`, la base no lleva el centinela. Esto evita el caso de un `.env.testing` ausente que haga caer en `.env`.
4. `DB::prohibitDestructiveCommands()` está activo salvo que se defina `DB_ALLOW_DESTRUCTIVE_COMMANDS=true`. Solo se activa para una operación puntual y aprobada.

## Secuencia de aprovisionamiento

Cada paso requiere aprobación explícita. Se ejecutan en este orden.

### Desarrollo

1. Hacer un backup:
   - `docker compose exec -T postgres pg_dumpall -U orbixa_bootstrap --globals-only > backup/globals.sql`
   - `docker compose exec -T postgres pg_dump -U orbixa_bootstrap -Fc orbixa > backup/orbixa.dump`
2. Crear los roles: `scripts/db/provision.sh dev roles`.
3. Aplicar privilegios de base y esquema: `scripts/db/provision.sh dev database`.
4. Transferir la propiedad de las 9 tablas al migrador: `scripts/db/provision.sh dev transfer`.
5. Editar `.env`:
   - `DB_USERNAME=orbixa_app` y `DB_PASSWORD`;
   - `DB_MIGRATOR_*`;
   - un solo `QUEUE_CONNECTION=redis` y `CACHE_STORE=redis`;
   - quitar `POSTGRES_ADMIN_PASSWORD`.
6. Migrar: `composer db:migrate`. Se aplican privilegios por tabla y la fundación `platform`.
7. Verificar: `scripts/db/provision.sh dev verify`.

### Pruebas

1. Levantar el servidor: `docker compose --profile test up -d postgres-test`. Usa tmpfs, así que es efímero.
2. Provisionar:
   - `scripts/db/provision.sh test roles`
   - `scripts/db/provision.sh test database`
   - `scripts/db/provision.sh test sentinel`
3. Migrar: `composer test:db:migrate`.
4. Ejecutar la suite: `composer test:security`, y después `php artisan test`.

### Rollback por paso

| Paso | Cómo revertirlo |
| --- | --- |
| Roles | `DROP OWNED BY orbixa_app, orbixa_migrator` en cada base y luego `DROP ROLE`. Solo con autorización. |
| Base y esquema | `ALTER SCHEMA public OWNER TO pg_database_owner; GRANT USAGE ON SCHEMA public TO PUBLIC;` y revocar los grants a los roles. |
| Transferencia de tablas | `ALTER TABLE public.<tabla> OWNER TO orbixa_bootstrap` para cada una de las 9. |
| Migraciones | `down()` de cada migración, con `DB_ALLOW_DESTRUCTIVE_COMMANDS=true` puntual y autorizado. |

## Privilegios de las tablas globales (sin `organization_id`)

| Tabla | `orbixa_app` | Motivo |
| --- | --- | --- |
| `migrations` | — | Historial de esquema; solo el dueño. |
| `users` | SELECT, INSERT; UPDATE solo en `name`, `email`, `email_verified_at`, `password`, `remember_token`, `updated_at` | Identidad global. El runtime no puede reescribir `id` ni `created_at`. Sin DELETE hasta definir la baja de cuentas. |
| `password_reset_tokens` | SELECT, INSERT, DELETE | El broker borra e inserta; nunca actualiza. |
| `sessions` | SELECT, INSERT, UPDATE, DELETE | Driver de sesión en base de datos, incluida la limpieza (GC). |
| `cache`, `cache_locks` | — | La caché vive en Redis (Predis). |
| `jobs`, `job_batches` | — | Las colas viven en Redis. `job_batches` se concede cuando se adopte batching. |
| `failed_jobs` | SELECT, INSERT, DELETE | El driver de fallidos registra, lista y olvida. |
| Secuencias | `users_id_seq` y `failed_jobs_id_seq`: USAGE | Resto: ninguno. |

Cualquier cambio requiere actualizar la migración `2026_11_03_000100_*` y `GlobalTablePrivilegesTest`. Ambos se revisan juntos.

## Riesgos residuales y decisiones pendientes

**Riesgo aceptado (decisión de arquitectura):** RLS sobre un GUC es defensa en profundidad, no autenticación. Una inyección SQL podría ejecutar `set_config` y cambiar de tenant. Controles:
- SQL siempre parametrizado;
- `TenantContext` como único punto que escribe el GUC, y solo tras verificar la membresía;
- el escaneo estático `TenantGucUsageTest`, que no detecta SQL construido dinámicamente;
- las policies de Laravel, que son la autoridad.

- **Un rol puede fijarse a sí mismo valores por defecto** (`ALTER ROLE orbixa_app SET …`). PostgreSQL no permite revocarlo. Hay dos defensas:
  - `RuntimeRolePrivilegesTest` y `90_verify.sql` comprueban que no exista ningún valor por defecto `app.*`;
  - el escaneo de código prohíbe `ALTER ROLE` en la aplicación.
- **Relay de la outbox:** se aprobó e implementó la opción A (`outbox-relay.md`). Es el único acceso entre organizaciones que existe, y se limita a filas pendientes de la outbox.
- **`MembershipVerifier` se ejecuta sin contexto de tenant.** En `01-identity-tenancy` la consulta de membresías no puede depender de tablas filtradas por RLS de organización. Requiere una ruta de lectura acotada y revisada.
- **`REVOKE CONNECT ON DATABASE postgres FROM PUBLIC`** afecta a todo el servidor. Es intencional: ningún rol de la app usa `postgres`, y `pg_isready` no necesita CONNECT. Revisarlo si se añaden herramientas de monitoreo.
- **`platform` es permanente:** el `down()` de `2026_11_03_000200_*` lanza una excepción. Retirarlo exige un cambio revisado.
- **FK compuestas:** la plantilla incluye `UNIQUE (organization_id, id)`. La prueba de rechazo 23503 llegará con la primera tabla hija.
- **`users.id` sigue siendo `bigint`** (migración por defecto de Laravel). DATA_MODEL y ADR-004 piden `uuid`. La conversión pertenece a `01-identity-tenancy`, dueño de Identity. Hoy la tabla tiene 0 filas, así que es barato hacerlo antes de crear membresías. Hay que convertir `users.id` y `sessions.user_id`, y revisar cualquier FK.
- **Audit:** se queda como esqueleto. `audit_log` llega con su primer consumidor.
- **TLS:** `ssl=off` solo es aceptable en local. Fuera de local se usa `DB_SSLMODE=verify-full`.
