# Capacidad: Identidad, organizaciones y acceso

## ADDED Requirements

### Requirement: Crear organizaciones
El sistema SHALL permitir a un usuario autenticado crear una organización, generando su identificador en el servidor y dejando al creador como `org_owner` con membresía activa en la misma transacción.

#### Scenario: Creación con owner
- **GIVEN** un usuario autenticado
- **WHEN** crea una organización
- **THEN** la organización existe con un UUID generado por el servidor, el creador tiene membresía activa con rol `org_owner` y se registra `organization.created` en la outbox

#### Scenario: Creación sin owner se revierte
- **GIVEN** un caso de uso que crea una organización
- **WHEN** termina sin crear la membresía activa del creador
- **THEN** la transacción se revierte y no queda organización ni evento

#### Scenario: Usuario no autenticado
- **GIVEN** una petición sin sesión autenticada
- **WHEN** intenta crear una organización
- **THEN** se rechaza sin crear datos

### Requirement: Membresía multi-organización y organización activa
El sistema SHALL permitir que un usuario sea miembro de varias organizaciones y SHALL resolver la organización activa desde la ruta, verificando la membresía activa en cada petición antes de abrir el contexto de tenant.

#### Scenario: Cambio de organización
- **GIVEN** un usuario miembro activo de las organizaciones A y B
- **WHEN** cambia la organización activa de A a B
- **THEN** se regenera la sesión y solo ve recursos y roles de B

#### Scenario: Organización sin membresía
- **GIVEN** un usuario sin membresía activa en la organización B
- **WHEN** solicita cualquier ruta de B
- **THEN** recibe 404 y no se abre contexto de tenant

### Requirement: Aislamiento estricto
El sistema MUST responder 404 a cualquier recurso de otra organización, aun con un identificador válido, y MUST responder 403 solo cuando el recurso pertenece a la organización activa y el actor no tiene el permiso.

#### Scenario: UUID válido de otra organización
- **GIVEN** un usuario de la organización A
- **WHEN** consulta o modifica un recurso de B mediante su UUID válido
- **THEN** recibe 404 idéntico al de un recurso inexistente, sin datos de B

#### Scenario: Recurso propio sin permiso
- **GIVEN** un miembro de A sin el permiso requerido
- **WHEN** intenta una operación sobre un recurso de A
- **THEN** recibe 403 y no se modifica nada

### Requirement: Verificación de membresía antes del contexto
El sistema MUST verificar la membresía activa dentro de la misma transacción en que fija el contexto de tenant, mediante funciones de base de datos acotadas que solo devuelven si existe la membresía o las organizaciones del usuario, sin abrir lectura general de membresías.

#### Scenario: Membresía revocada
- **GIVEN** un usuario cuya membresía en A fue revocada
- **WHEN** se intenta abrir el contexto de A para ese usuario
- **THEN** se deniega y ninguna consulta organizacional devuelve filas

#### Scenario: Lectura directa sin contexto
- **GIVEN** el rol runtime sin contexto de tenant
- **WHEN** consulta directamente la tabla de membresías
- **THEN** obtiene 0 filas

### Requirement: Invitaciones
El sistema SHALL crear invitaciones con un token aleatorio de 256 bits almacenado solo como hash, válido 7 días, de un solo uso y ligado al email verificado del invitado, y SHALL negar invitar a un rol con permisos que el invitador no posee.

#### Scenario: Aceptación válida
- **GIVEN** una invitación vigente para el email verificado del usuario
- **WHEN** el usuario la acepta
- **THEN** se crea su membresía activa con el rol invitado, la invitación queda usada y se registra `organization.member.joined`

#### Scenario: Token reutilizado, expirado o de otro email
- **GIVEN** una invitación usada, expirada, revocada o emitida para otro email
- **WHEN** se intenta aceptar
- **THEN** se rechaza con la misma respuesta genérica y no se crea membresía

#### Scenario: Invitar a un rol superior
- **GIVEN** un `org_admin`
- **WHEN** invita a alguien como `org_owner`
- **THEN** se deniega

### Requirement: Revocación inmediata
El sistema MUST invalidar el acceso de una membresía revocada en la siguiente petición o ejecución de job, sin depender de expiración de caché.

#### Scenario: Revocación con sesión abierta
- **GIVEN** un usuario con sesión activa en A
- **WHEN** un administrador revoca su membresía
- **THEN** su siguiente petición a A recibe 404, la organización activa de su sesión se limpia y se registra `organization.member.revoked`

#### Scenario: Job encolado antes de la revocación
- **GIVEN** un job encolado con la organización A y el usuario como actor
- **WHEN** la membresía se revoca antes de que el job se ejecute
- **THEN** el job falla cerrado sin efectos y sin reintentos

### Requirement: Roles sin autoescalamiento
El sistema MUST negar por defecto toda operación sin permiso explícito y MUST impedir que un actor asigne roles con permisos que no posee, modifique sus propios roles o deje la organización sin `org_owner`.

#### Scenario: Asignación de rol superior
- **GIVEN** un `org_admin`
- **WHEN** intenta asignar `org_owner` a otro miembro o a sí mismo
- **THEN** se deniega

#### Scenario: Último owner
- **GIVEN** una organización con un único `org_owner`
- **WHEN** se intenta revocar o degradar a ese owner
- **THEN** se rechaza

### Requirement: Separación de roles de plataforma
El sistema MUST negar a un operador de plataforma el acceso a datos de una organización en la que no tiene membresía, y MUST negar el dashboard de colas fuera del entorno local.

#### Scenario: Operador sin membresía
- **GIVEN** un usuario con rol de plataforma sin membresía ni grant de soporte en B
- **WHEN** consulta datos de B
- **THEN** recibe 404

### Requirement: Rol visor sin acceso implícito
El sistema SHALL asignar al rol `viewer` cero permisos de dominio por defecto; el acceso de monitoreo mediante grants se define en `04-monitoring-access`.

#### Scenario: Viewer sin permisos
- **GIVEN** un miembro activo de A con rol `viewer` y sin grants
- **WHEN** intenta crear, editar, exportar o gestionar miembros
- **THEN** se deniega cada operación

### Requirement: PostgreSQL RLS por organización
El sistema SHALL aplicar RLS forzada a toda entidad tenant-scoped y SHALL establecer el contexto de organización de forma transaccional con un rol runtime sin `BYPASSRLS`.

#### Scenario: Conexión reutilizada
- **GIVEN** una conexión de pool que atendió previamente a la organización A
- **WHEN** atiende una consulta de la organización B o una operación sin tenant
- **THEN** solo devuelve filas de B cuando existe contexto válido, o ninguna fila si falta contexto
