# Backups, PITR y recuperación

Parte de `00-platform-foundation`, tarea 5.

**Estado:** runbook propuesto, **sin probar**.

**Decisión del 2026-11-03:** el ensayo de PITR se pospone. Mientras Orbixa solo corra en desarrollo local se usa el backup lógico (`pg_dump`). El ensayo es **requisito obligatorio antes de cualquier entorno con datos reales** (staging o producción), y la tarea 5 de `00-platform-foundation` queda abierta en su parte de "recuperación probada".

## Situación actual (local)

`archive_mode=off`, `ssl=off`, volumen Docker sin cifrar. Hoy solo es posible el backup lógico. Es aceptable en local, pero **no** en ningún entorno con datos reales.

## Objetivos a confirmar con operación

| Métrica | Propuesta inicial | Decide |
| --- | --- | --- |
| RPO (pérdida máxima de datos) | ≤ 5 min | Negocio / operación |
| RTO (tiempo máximo de recuperación) | ≤ 1 h | Negocio / operación |
| Retención | 14 días de PITR + 12 backups mensuales | Legal / contrato |

## Diseño propuesto

- **Herramienta:** pgBackRest (alternativa: WAL-G), sobre un repositorio en almacenamiento de objetos con **cifrado del lado del cliente** (`repo1-cipher-type=aes-256-cbc`). La clave se guarda en un gestor de secretos, nunca en Git.
- **PostgreSQL:**
  - `wal_level=replica`;
  - `archive_mode=on`;
  - `archive_command='pgbackrest --stanza=orbixa archive-push %p'`;
  - `archive_timeout=60s`.
- **Calendario:** un backup completo semanal, uno diferencial diario y archivado de WAL continuo.
- **Rol:** pgBackRest corre en el host o contenedor de PostgreSQL con el usuario del sistema `postgres`. Ningún rol de la aplicación tiene privilegios de backup.
- **Globals:** `pg_dumpall --globals-only` versionado aparte (roles y ACL). En la restauración, las contraseñas se regeneran: no se restauran desde el backup.

## Restauración (PITR)

1. Detener la aplicación y los workers (`php artisan down`, parar las colas).
2. `pgbackrest --stanza=orbixa --type=time --target="<UTC ISO-8601>" --target-action=promote restore` sobre un **directorio de datos nuevo**. Nunca sobre el original sin copia.
3. Arrancar PostgreSQL y esperar a que termine la recuperación.
4. Verificar:
   - `scripts/db/provision.sh <env> verify` (roles, RLS, dueños);
   - `php artisan orbixa:db:verify --with-migrator`;
   - `migrate:status`.
5. Ejecutar un *smoke test* de aislamiento: la suite `Security`, contra una copia y nunca contra producción.
6. Reabrir el tráfico. Registrar la hora objetivo, la duración real (RTO) y la pérdida real (RPO).

## Ensayo obligatorio (evidencia de la tarea 5)

En un entorno aislado:
1. Crear datos de dos organizaciones.
2. Anotar T₀.
3. Crear más datos.
4. Restaurar a T₀.
5. Comprobar con la suite `Security`, y con conteos por organización, que el estado coincide con T₀ y que el aislamiento sigue intacto.

Guardar el log del ensayo como evidencia en el cambio OpenSpec.

## Decisiones pendientes

- Elegir pgBackRest o WAL-G, y el proveedor de almacenamiento.
- Confirmar RPO, RTO y retención.
- Elegir el entorno donde se hará el ensayo de restauración.
