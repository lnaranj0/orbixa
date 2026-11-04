# Capacidad: teams-and-portfolio

## ADDED Requirements

### Requirement: Equipos jerárquicos
El sistema MUST soportar relaciones padre-hijo entre equipos de la misma organización, MUST impedir ciclos aun con reorganizaciones concurrentes y MUST limitar la profundidad de la jerarquía a 5 niveles.

#### Scenario: Ciclo directo o indirecto
- **GIVEN** los equipos A y B, con B hijo de A
- **WHEN** se intenta mover A bajo B, o A bajo sí mismo
- **THEN** se rechaza y la jerarquía no cambia

#### Scenario: Reorganización concurrente
- **GIVEN** dos transacciones simultáneas que mueven A bajo B y B bajo A
- **WHEN** ambas confirman
- **THEN** solo una tiene éxito y no queda ningún ciclo

#### Scenario: Profundidad máxima
- **GIVEN** una rama con 5 niveles
- **WHEN** se intenta crear o mover un equipo que la llevaría a 6 niveles
- **THEN** se rechaza

#### Scenario: Padre de otra organización
- **GIVEN** un equipo de la organización B
- **WHEN** un usuario de A lo indica como padre de un equipo de A
- **THEN** se rechaza con la misma respuesta que para un identificador inexistente

#### Scenario: Gestión sin permiso
- **GIVEN** un miembro sin `teams.manage` ni rol de líder sobre el equipo
- **WHEN** intenta crear, mover o archivar equipos
- **THEN** se deniega

### Requirement: Membresía de equipo sin acceso implícito
El sistema MUST validar la jerarquía al agregar miembros o reorganizar equipos, y la membresía de equipo MUST NOT conceder acceso a todos los proyectos de la organización ni a los proyectos de equipos padre o hijos.

#### Scenario: Membresía de equipo sin acceso implícito
- **GIVEN** un equipo con un subequipo
- **WHEN** se agrega un miembro o se reorganiza la jerarquía
- **THEN** se valida la ausencia de ciclos y el miembro solo accede a los proyectos enlazados explícitamente a su equipo

#### Scenario: Proyecto del equipo padre
- **GIVEN** un proyecto enlazado al equipo padre A y un miembro solo del subequipo B
- **WHEN** el miembro consulta el proyecto
- **THEN** recibe 404

#### Scenario: Líder fuera de su rama
- **GIVEN** el líder del equipo B, hijo de A y hermano de C
- **WHEN** intenta gestionar A o C, o designar a otro líder
- **THEN** se deniega; sí puede gestionar B y sus subequipos

#### Scenario: Revocación de membresía
- **GIVEN** un usuario miembro de equipos y proyectos de la organización
- **WHEN** se revoca su membresía en la organización
- **THEN** sus membresías de equipo y de proyecto se cierran en la misma transacción, conservando el historial

### Requirement: Proyectos aislados
El sistema MUST aislar cada proyecto por organización y MUST mostrar un proyecto solo a quien tenga permiso de organización, sea miembro directo o pertenezca a un equipo enlazado directamente; los proyectos `restricted` MUST requerir membresía directa o permiso de organización.

#### Scenario: Proyectos aislados
- **GIVEN** un usuario de otra organización
- **WHEN** intenta consultar un proyecto por UUID
- **THEN** no recibe datos y obtiene 404

#### Scenario: Miembro sin asignación
- **GIVEN** un developer de la organización sin membresía directa ni por equipo en el proyecto
- **WHEN** consulta el proyecto por UUID o lo busca en listados
- **THEN** recibe 404 y el proyecto no aparece en listas, búsquedas ni cursores

#### Scenario: Proyecto restringido por enlace de equipo
- **GIVEN** un proyecto `restricted` enlazado a un equipo del usuario
- **WHEN** el usuario sin membresía directa lo consulta
- **THEN** recibe 404

#### Scenario: Proyecto visible sin permiso de gestión
- **GIVEN** un miembro que puede ver el proyecto pero no gestionarlo
- **WHEN** intenta modificarlo
- **THEN** recibe 403 y no cambia nada

#### Scenario: Clave duplicada
- **GIVEN** un proyecto existente con la clave `ORBX`, visible u oculto para el usuario
- **WHEN** se crea otro proyecto con la misma clave
- **THEN** se rechaza con un error genérico idéntico en ambos casos

### Requirement: Asignación de equipos
El sistema MUST permitir enlazar equipos autorizados a proyectos preservando los límites de organización y un historial de asignaciones trazable, y MUST exigir gestionar tanto el proyecto como el equipo para enlazarlos.

#### Scenario: Asignación de equipos
- **GIVEN** miembros de diferentes equipos
- **WHEN** se asocia un proyecto
- **THEN** solo autorizados pueden leerlo

#### Scenario: Enlace para heredar acceso
- **GIVEN** el líder de un equipo sin gestión sobre un proyecto
- **WHEN** intenta enlazar su equipo a ese proyecto
- **THEN** se deniega

#### Scenario: Autoasignación
- **GIVEN** un miembro de la organización
- **WHEN** intenta agregarse a sí mismo a un equipo o proyecto
- **THEN** se deniega

#### Scenario: Historial de asignaciones
- **GIVEN** un miembro agregado y luego retirado de un equipo o proyecto
- **WHEN** se consulta el historial
- **THEN** constan ambas operaciones con actor e instante, y se registraron eventos sin datos personales

### Requirement: APIs paginadas sin fuga de datos ocultos
El sistema SHALL exponer listados paginados por cursor con filtros de lista blanca aplicados después del predicado de visibilidad, y MUST NOT revelar totales ni elementos no visibles.

#### Scenario: Paginación y filtros
- **GIVEN** un usuario que ve 3 de 5 proyectos de su organización
- **WHEN** pagina, filtra por `confidentiality=restricted` o manipula el cursor
- **THEN** solo obtiene sus proyectos visibles, sin totales globales ni datos de los ocultos
