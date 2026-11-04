# Migraciones: proceso, revisión y rollback

Parte de `00-platform-foundation`, tarea 5.

## Dónde viven

- Cada módulo guarda sus migraciones en `modules/<Módulo>/Database/Migrations`. Las carga su ServiceProvider.
- `database/migrations` conserva solo las 3 migraciones originales de Laravel (`users`, `cache`, `jobs`).
- Cada tabla nueva se declara en el `ModuleManifest` de su módulo. `TableOwnershipTest` falla si una tabla no tiene dueño o tiene más de uno.

## Reglas para escribir una migración

1. **Se ejecuta como `orbixa_migrator`:** `composer db:migrate` (desarrollo) o `composer test:db:migrate` (pruebas). `MigrationConnectionGuard` rechaza cualquier otra conexión o rol, y también una base de pruebas sin centinela.
2. **No hay privilegios por defecto para tablas.** Cada migración concede explícitamente lo que necesita el runtime: `grant select, insert … to {runtime}`, usando siempre `PostgresRoles::runtime()`. TRUNCATE, REFERENCES y TRIGGER no se conceden nunca.
3. **Las tablas de tenant usan la plantilla** de `2026_11_03_000200`/`000300`:
   - columna `organization_id uuid not null` y `UNIQUE (organization_id, id)`;
   - `ENABLE` + `FORCE ROW LEVEL SECURITY`;
   - una sola policy `FOR ALL TO <runtime>` con `organization_id = (select platform.current_organization_id())` en USING y WITH CHECK;
   - el trigger `BEFORE UPDATE OF organization_id` → `platform.prevent_organization_id_change()`;
   - FK compuestas `(organization_id, x_id) → (organization_id, id)` hacia otras tablas de tenant.

   `RlsCatalogTest` lo verifica sobre todo el catálogo.
4. **Tablas globales** (sin `organization_id`): se añaden a la allowlist de `RlsCatalogTest` y a `GlobalTablePrivilegesTest`, con su matriz de privilegios revisada.
5. Sin vistas `SECURITY DEFINER`, sin funciones `SECURITY DEFINER` y sin `search_path` mutable en funciones.
6. Los cambios de contratos, migraciones, permisos y RLS requieren **revisión explícita** (CLAUDE.md). Antes de fusionar se ejecutan `security-reviewer` y `postgres-reviewer`.

## Cambios compatibles (expand / contract)

Para tablas con datos:
1. Añadir columnas nullable o con default.
2. Rellenar en lotes con el rol runtime dentro del contexto de cada organización. Con FORCE RLS, el migrador no ve filas.
3. Cambiar el código.
4. Aplicar restricciones (`NOT NULL`, `VALIDATE CONSTRAINT`) en una migración posterior.
5. Retirar lo obsoleto en otra versión.

Se evitan bloqueos largos: `lock_timeout` del migrador = 5 s, y los índices sobre tablas grandes se crean con `CREATE INDEX CONCURRENTLY` en una migración sin transacción.

## Rollback

- `migrate:rollback` está **bloqueado** (`DB::prohibitDestructiveCommands`). Solo se habilita con `DB_ALLOW_DESTRUCTIVE_COMMANDS=true`, para una operación puntual aprobada por una persona.
- Las migraciones de fundación (`000200`, `000300`) son permanentes: su `down()` lanza una excepción. Revertirlas exige un cambio nuevo y revisado.
- La estrategia preferida es **roll-forward**: una migración nueva que corrige la anterior.
- Antes de cualquier migración en un entorno con datos: backup lógico (`pg_dump -Fc`) o un punto de restauración PITR anotado (ver `backup-pitr.md`).

## Evidencia por cambio

- La salida de `migrate:status --database=pgsql_migrator`.
- `php artisan orbixa:db:verify --with-migrator`.
- La suite `Security` en verde contra el servidor de pruebas.
- Para cambios de privilegios o RLS: la salida de `scripts/db/provision.sh <dev|test> verify`.
