# Capacidad: Gestión de backlog y trabajo

## ADDED Requirements

### Requirement: Crear elementos jerárquicos
El sistema SHALL permitir crear items de tipo initiative, epic, feature, task, bug, chore o incident en un proyecto visible y no archivado, asignándoles una clave secuencial única por proyecto, y MUST aceptar como padre solo un item del mismo proyecto con un tipo compatible.

#### Scenario: Clave secuencial
- **GIVEN** un proyecto `ORBX` visible y el permiso `work.create`
- **WHEN** se crean dos items, también de forma concurrente
- **THEN** reciben claves distintas `ORBX-1` y `ORBX-2`, sin huecos por la concurrencia

#### Scenario: Padre válido
- **GIVEN** una epic y una task del mismo proyecto
- **WHEN** se crea una task con la epic como padre, o una subtarea bajo la task
- **THEN** se acepta; un padre de otro proyecto, de tipo incompatible, inexistente o de otra organización se rechaza con el mismo 422

### Requirement: Prevención de ciclos
El sistema MUST rechazar ciclos en la jerarquía, aun con cambios concurrentes, y MUST limitar la profundidad a 5 niveles.

#### Scenario: Descendiente como padre
- **GIVEN** un conjunto jerárquico A → B → C
- **WHEN** se intenta asignar C como padre de A
- **THEN** la operación es rechazada y la jerarquía no cambia

### Requirement: Priorización y clasificación
El sistema SHALL guardar en la actividad del item cada cambio de prioridad, etiquetas o estimación, con autor y fecha.

#### Scenario: Historial de clasificación
- **GIVEN** un work item existente
- **WHEN** cambia prioridad, etiquetas o estimación
- **THEN** se guarda historial con autor y fecha, y un evento sin texto libre

### Requirement: Visibilidad heredada del proyecto
El sistema MUST mostrar un item solo a quien puede ver su proyecto, en listados, detalle, "Mi trabajo", actividad, dependencias y padres.

#### Scenario: Item de proyecto no visible
- **GIVEN** un item de un proyecto que el usuario no ve, o de otra organización
- **WHEN** lo consulta por UUID o por clave, o lo busca en listados
- **THEN** recibe 404 idéntico al de un item inexistente y no aparece en listas ni cursores

#### Scenario: Asignado que pierde el proyecto
- **GIVEN** un usuario asignado a un item
- **WHEN** deja de ver el proyecto
- **THEN** el item desaparece de "Mi trabajo" y su detalle responde 404

### Requirement: Asignaciones y subequipos
El sistema SHALL permitir un owner humano principal, colaboradores y equipos asociados, y MUST asignar solo a miembros activos que puedan ver el proyecto, sin conceder visibilidad por la asignación ni por el equipo.

#### Scenario: Asignación válida
- **GIVEN** un work item y un actor con `work.assign`
- **WHEN** asigna un owner humano, colaboradores y un equipo
- **THEN** queda un único owner activo, sin duplicados, con historial y sin confundir identidades

#### Scenario: Asignado sin acceso
- **GIVEN** un miembro que no ve el proyecto, revocado o de otra organización
- **WHEN** se le intenta asignar
- **THEN** se rechaza con el mismo 422 genérico

#### Scenario: Autoasignación
- **GIVEN** un miembro sin `work.assign`, o con él
- **WHEN** intenta asignarse a sí mismo
- **THEN** se deniega

#### Scenario: Revocación
- **GIVEN** un miembro con asignaciones activas
- **WHEN** se revoca su membresía de organización
- **THEN** sus asignaciones se cierran en la misma transacción, conservando el historial

### Requirement: Dependencias
El sistema MUST rechazar dependencias que introduzcan ciclos, autodependencias o items de otro proyecto.

#### Scenario: Ciclo de dependencias
- **GIVEN** dos tareas A y B del mismo proyecto, con A bloqueando a B
- **WHEN** se intenta que B bloquee a A, también de forma concurrente
- **THEN** se rechaza y no queda ningún ciclo

### Requirement: Feed de actividad
El sistema SHALL registrar cada modificación confirmada como un evento inmutable con diff sanitizado.

#### Scenario: Evento inmutable
- **GIVEN** una modificación a un item
- **WHEN** se confirma la transacción
- **THEN** se conserva un evento que el rol runtime no puede modificar ni borrar, sin el texto de la descripción

### Requirement: Concurrencia
El sistema MUST rechazar una edición basada en una versión obsoleta sin pisar el cambio previo.

#### Scenario: Edición simultánea
- **GIVEN** dos ediciones simultáneas del mismo work item
- **WHEN** la segunda utiliza la versión obsoleta
- **THEN** recibe 409 y el cambio previo se conserva

### Requirement: Finalizar una tarea sin integraciones
El sistema SHALL permitir llevar una tarea de `backlog` a `done` mediante transiciones permitidas, sin GitHub ni OpenSpec, y MUST rechazar transiciones no permitidas.

#### Scenario: Ciclo de vida
- **GIVEN** una task en `backlog` y un actor con `work.transition`
- **WHEN** la lleva a `ready`, `active`, `review` y `done`
- **THEN** queda completada con fecha, y un salto `backlog → done` se rechaza con 422
