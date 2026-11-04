# Diseño: 01-identity-tenancy

Decisiones aprobadas el 2026-11-03 (opción A y D2–D10). Las propusieron los agentes `architect` y `security-reviewer`.

## Contexto y decisiones
- Base de datos: PostgreSQL 18, `uuid` (uuidv7) para identificadores, `timestamptz` para eventos y `jsonb` solo para metadatos no relacionales.
- Arquitectura: monolito modular Laravel con capas `Domain`, `Application`, `Infrastructure`, `Presentation`, `Contracts`, `Database/Migrations`, `Tests`.
- **Ownership:**
  - **IdentityTenancy** (esquema `identity`): `users`, `password_reset_tokens`, `organizations`, `organization_memberships`, `invitations`.
  - **Access** (esquema `access`): `roles`, `role_permissions`, `membership_roles`. El catálogo de permisos es un enum en código (`Access\Contracts\Permission`).
  - Los módulos externos consumen contratos y eventos, nunca consultas directas a tablas ajenas.
- Los registros organizacionales llevan `organization_id`. Las comprobaciones de acceso son deny-by-default, con SQL RLS forzada y validación Laravel.
- Todo evento relevante conserva `event_id`, `organization_id`, `correlation_id`, versión, instante y actor. Los payloads llevan solo IDs, nunca email ni nombre.

## Verificación de membresía (opción A)
- **El problema:** las membresías están bajo RLS por organización, pero hay que verificarlas *antes* de existir el contexto de tenant.
- **Funciones acotadas `SECURITY DEFINER` en el esquema `identity`:**
  - `has_active_membership(user_id, organization_id) → boolean`;
  - `organizations_for_user(user_id) → (id, name, slug)`;
  - `admissible_invitation(...)` para aceptar invitaciones.
- **Propiedad y permisos:**
  - El dueño es un rol `NOLOGIN` dedicado (`orbixa_membership_reader`) sin BYPASSRLS.
  - Ese rol lee solo las columnas necesarias mediante una policy nominal `FOR SELECT TO orbixa_membership_reader`.
  - Las funciones fijan `SET search_path = pg_catalog, pg_temp`, `REVOKE EXECUTE FROM PUBLIC` y `GRANT EXECUTE` solo al runtime.
- **Excepción en `RlsCatalogTest`:** solo para esas funciones, con firma, dueño y `proconfig` exactos. Cualquier otra función `SECURITY DEFINER` sigue fallando la prueba.
- **Dónde se verifica:** la membresía se comprueba **dentro** de la transacción donde se fija el contexto. Así no queda ventana entre la verificación y el uso (TOCTOU).
- **Alternativa descartada:** un segundo GUC `app.user_id` con policies extra en tres tablas. Ampliaba la superficie de RLS y añadía otro GUC que limpiar en conexiones reutilizadas.

## Entradas sin membresía previa (contratos de Platform)
- **`TenantContext::runForNewOrganization(actor, callback)`:**
  - el UUID de la organización lo genera el servidor;
  - postcondición: al terminar, el actor debe tener membresía activa, y si no la tiene se revierte todo.
- **`TenantContext::runForAdmission(actor, organización, AdmissionTicket, callback)`:**
  - un `AdmissionVerifier` valida la invitación dentro de la transacción;
  - postcondición: membresía activa creada.
  - `AdmissionTicket` no se puede serializar ni mostrar, así que el token nunca llega a logs ni a colas.

## Usuarios (D2)
- `public.users` pasa a `identity.users` (y lo mismo `password_reset_tokens`) con `id uuid default uuidv7()`. `sessions.user_id` pasa a `uuid`.
- La migración la ejecuta el migrador y es solo roll-forward. Aborta si `users` tiene filas o si `sessions.user_id` no es nulo. `down()` lanza una excepción.
- El acceso del runtime a `identity.users` es por contrato y nunca lista usuarios globales. Una RLS sobre `users` queda como decisión posterior.

## Organización activa y respuestas (D3, D9)
- La ruta `/o/{organización}` es la fuente de verdad; la sesión solo recuerda la última organización usada.
- **Cambiar de organización:** POST, verificación de membresía y `session()->regenerate()`.
- **Respuestas:**
  - recurso de otra organización, organización sin membresía o inexistente: **404** uniforme;
  - recurso propio sin permiso: **403**.
- **Transacciones:** una por caso de uso (`runFor`), no por petición HTTP, para no retener transacciones durante el render.
- **Revocación:** en 01 no hay caché de membresías ni permisos, así que la siguiente petición ya falla. Además se limpia la organización activa de la sesión.

## Autenticación (decisión del 2026-11-03)
- **Laravel Fortify** sin interfaz, con **login y logout** únicamente. El login está limitado a 5 intentos por minuto por email e IP. Registro, recuperación de contraseña, verificación de email, 2FA y passkeys quedan desactivados hasta decidirlos (por ejemplo, SSO o MFA).
- No se ejecutó `fortify:install`, porque publicaría una migración sobre `users` en `public`.
- Los usuarios locales se crean con `php artisan orbixa:user:create {email} --verified`. La contraseña se pide sin eco y nunca se registra.
- **Enlace de invitación:** se muestra una vez en pantalla y no se envía por correo, porque el mailer de desarrollo es `log` y escribiría el token en los logs. Al abrir el enlace, el token pasa a la sesión cifrada y la URL se limpia con una redirección.
- **Pantallas mínimas:** Blade + Tailwind, provisionales hasta `11-design-system`.

## Roles y permisos (D5, D7)
- Plantillas por organización: `org_owner`, `org_admin`, `project_manager`, `team_lead`, `developer`, `reviewer`, `viewer`. Se copian al crear la organización, sin `db:seed`.
- Solo se conceden las celdas "Sí" de `docs/PERMISSIONS.md`; las "Config." quedan sin conceder. `viewer`: cero permisos de dominio.
- **Reglas contra el autoescalamiento:**
  - se asigna un rol solo si sus permisos están contenidos en los del actor;
  - `org_owner` solo lo asigna un owner;
  - nadie modifica sus propios roles;
  - debe quedar al menos un owner, comprobado con `SELECT … FOR UPDATE` sobre la organización.

## Invitaciones (D8)
- **Token y almacenamiento:** 32 bytes de `random_bytes` en base64url. Solo se guarda `sha256` (`token_hash bytea UNIQUE`).
- **Validez y uso:** expira a los 7 días. Uso único atómico mediante `UPDATE … WHERE accepted_at IS NULL AND revoked_at IS NULL AND expires_at > now()`. Ligado al email verificado del invitado.
- **Rechazos:** respuesta genérica para token inexistente, usado, expirado o de otro email, con rate limiting por IP+email y por token.

## Operador de plataforma (D4)
- Sin membresía, `runFor` lo deniega: no existe lectura universal. Los grants temporales de soporte llegan en un cambio posterior.
- El dashboard de Horizon sigue denegado fuera de `local`.

## Jobs
- Los jobs con contexto serializan `organization_id` y `user_id`, solo IDs.
- Al ejecutarse, un middleware resuelve el actor y llama a `runFor`, que vuelve a verificar la membresía. Si la membresía está revocada, falla cerrado sin reintentos.

## Eventos (D10)
`organization.created`, `organization.member.invited`, `organization.member.joined`, `organization.member.role_changed` y `organization.member.revoked`. Se publican por outbox en la misma transacción.

## Alcance movido (D6, D7)
- La jerarquía de equipos y "la membresía de equipo no implica acceso a todos los proyectos" pasan a `02-teams-portfolio`.
- El monitoreo con grants es de `04-monitoring-access`. En 01 solo se prueba que `viewer` no tiene permisos.

## Riesgos y decisiones pendientes
- Validar en CI las versiones exactas de Laravel, PostgreSQL y extensiones.
- Hacer las pruebas de integración reales sobre PostgreSQL 18 con el rol runtime antes de la UI.
- Definir la RLS sobre `identity.users` y los grants de soporte en cambios posteriores.

## Verificación de salida
Sin membresía activa no hay acceso. Las políticas y RLS bloquean accesos cruzados aun con identificadores válidos.
