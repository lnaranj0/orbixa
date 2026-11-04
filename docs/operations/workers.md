# Workers, colas y scheduler

Parte de `00-platform-foundation`, tarea 2. Decisión del 2026-11-03: **Laravel Horizon**.

## Procesos

| Proceso | Comando | Qué hace |
| --- | --- | --- |
| Workers | `php artisan horizon` | Procesa las colas de Redis (`default`) con auto-escalado (`config/horizon.php`: hasta 3 procesos en `local` y 10 en `production`). |
| Scheduler | `php artisan schedule:work` (local) o, en servidores, cron `* * * * * php artisan schedule:run` | Ejecuta `orbixa:outbox:relay` cada minuto (`withoutOverlapping`, `onOneServer`) y `horizon:snapshot` cada 5 minutos. |

Fuera de local, Horizon se supervisa con supervisord o systemd. Ejemplo de supervisord:

```ini
[program:orbixa-horizon]
command=php /srv/orbixa/artisan horizon
user=orbixa
autostart=true
autorestart=true
stopwaitsecs=3600
stdout_logfile=/var/log/orbixa/horizon.log
```

En cada despliegue, después de migrar: `php artisan horizon:terminate`. Supervisor lo reinicia con el código nuevo.

## Seguridad

- **Dashboard (`/horizon`):** muestra payloads de jobs de **todas** las organizaciones. Fuera de `local` el gate `viewHorizon` niega a todos. El acceso para `platform_operator`, con concesión auditada, llega en `01-identity-tenancy`.
- **Datos en los jobs:** solo identificadores y metadatos, nunca credenciales ni texto sensible (`docs/EVENTS.md`). Los jobs sensibles implementan `ShouldBeEncrypted`.
- **Redis:** requiere contraseña (`.secrets/redis_password`, `REDIS_PASSWORD`). Fuera de local, además, TLS.
- **Contexto de tenant en jobs:** el `correlation_id` viaja automáticamente (Laravel Context). Restaurar `TenantContext` en jobs exige identidad de actor o de servicio y llega en `01-identity-tenancy`. Mientras tanto, ningún job abre contexto de tenant por sí mismo.

## Evidencia

El 2026-11-03, en desarrollo, Horizon procesó un `DeliverDomainEvent` real desde Redis (`DONE`, `queue:failed` vacío) y se detuvo con `horizon:terminate`.
