# Capacidad: Organizaciones, equipos y acceso

## ADDED Requirements

### Requirement: Crear y seleccionar organizaciones
El sistema SHALL cumplir el comportamiento indicado para crear y seleccionar organizaciones.

#### Scenario: Caso verificable 1
- **GIVEN** un usuario autenticado
- **WHEN** crea una organización
- **THEN** queda como owner y todos los recursos nuevos se asocian a ella.

### Requirement: Membresía multi-organización
El sistema SHALL cumplir el comportamiento indicado para membresía multi-organización.

#### Scenario: Caso verificable 2
- **GIVEN** un usuario miembro de dos organizaciones
- **WHEN** cambia contexto
- **THEN** solo ve recursos y roles de la organización activa.

### Requirement: Aislamiento estricto
El sistema SHALL cumplir el comportamiento indicado para aislamiento estricto.

#### Scenario: Caso verificable 3
- **GIVEN** un usuario sin membresía en organización B
- **WHEN** consulta o modifica mediante ID válido de B
- **THEN** recibe 404/403 sin exposición de datos.

### Requirement: Jerarquía de equipos
El sistema SHALL cumplir el comportamiento indicado para jerarquía de equipos.

#### Scenario: Caso verificable 4
- **GIVEN** un equipo con un subequipo
- **WHEN** se agrega un miembro o se reorganiza la jerarquía
- **THEN** se valida ausencia de ciclos y la membresía no implica automáticamente acceso a todos los proyectos.

### Requirement: Invitaciones y revocación
El sistema SHALL cumplir el comportamiento indicado para invitaciones y revocación.

#### Scenario: Caso verificable 5
- **WHEN** se ejecuta el caso siguiente
- **THEN** se verifica: Dada una invitación válida, cuando el usuario la acepta, entonces se crea membresía con rol autorizado; al revocarlo se invalida su acceso y caché.

### Requirement: Separación de roles
El sistema SHALL cumplir el comportamiento indicado para separación de roles.

#### Scenario: Caso verificable 6
- **GIVEN** un administrador de plataforma sin grant de soporte
- **WHEN** consulta datos de tenant ajeno
- **THEN** se deniega la operación.

### Requirement: Rol visor sin acceso implícito
El sistema SHALL asignar al rol `viewer` únicamente operaciones de lectura y SHALL negar acceso a tareas de monitoreo sin grant vigente y aplicable.

#### Scenario: Viewer sin grant
- **GIVEN** un usuario viewer miembro activo de organización A sin grants
- **WHEN** abre el tablero de monitoreo o solicita un item de A
- **THEN** no obtiene datos y no puede editar o exportar.

### Requirement: PostgreSQL RLS por organización
El sistema SHALL aplicar RLS forzada para entidades tenant-scoped y SHALL establecer el contexto de organización de forma transaccional con un rol runtime sin `BYPASSRLS`.

#### Scenario: Conexión reutilizada
- **GIVEN** una conexión de pool que atendió previamente a organización A
- **WHEN** atiende una consulta de organización B o una operación sin tenant
- **THEN** solo devuelve filas de B cuando existe contexto válido, o ninguna fila si falta contexto.
