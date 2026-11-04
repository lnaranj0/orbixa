# Capacidad: Gestión de backlog y trabajo

## ADDED Requirements

### Requirement: Crear elementos jerárquicos
El sistema SHALL cumplir el comportamiento indicado para crear elementos jerárquicos.

#### Scenario: Caso verificable 1
- **GIVEN** proyecto y permisos válidos
- **WHEN** se crea iniciativa/epic/feature/task/bug/chore/incident
- **THEN** recibe código secuencial único y puede asociarse a padre válido.

### Requirement: Prevención de ciclos
El sistema SHALL cumplir el comportamiento indicado para prevención de ciclos.

#### Scenario: Caso verificable 2
- **GIVEN** un conjunto jerárquico
- **WHEN** se intenta asignar un descendiente como padre
- **THEN** la operación es rechazada.

### Requirement: Priorización y clasificación
El sistema SHALL cumplir el comportamiento indicado para priorización y clasificación.

#### Scenario: Caso verificable 3
- **GIVEN** work item existente
- **WHEN** cambia prioridad, etiquetas o estimación
- **THEN** se guarda historial, autor y fecha.

### Requirement: Asignaciones y subequipos
El sistema SHALL cumplir el comportamiento indicado para asignaciones y subequipos.

#### Scenario: Caso verificable 4
- **GIVEN** work item
- **WHEN** se asignan owner humano, colaboradores y agente
- **THEN** se aplican membresías y permisos del tenant sin confluir identidades.

### Requirement: Dependencias
El sistema SHALL cumplir el comportamiento indicado para dependencias.

#### Scenario: Caso verificable 5
- **WHEN** se ejecuta el caso siguiente
- **THEN** se verifica: Dadas dos tareas del mismo tenant, cuando se vinculan como bloqueadas, entonces no puede introducirse un ciclo de dependencias.

### Requirement: Feed de actividad
El sistema SHALL cumplir el comportamiento indicado para feed de actividad.

#### Scenario: Caso verificable 6
- **WHEN** se ejecuta el caso siguiente
- **THEN** se verifica: Dada modificación a actividad, cuando se confirma la transacción, entonces se conserva evento inmutable con diff sanitizado.

### Requirement: Concurrencia
El sistema SHALL cumplir el comportamiento indicado para concurrencia.

#### Scenario: Caso verificable 7
- **WHEN** se ejecuta el caso siguiente
- **THEN** se verifica: Dadas dos ediciones simultáneas del mismo work item, cuando la segunda utiliza versión obsoleta, entonces recibe conflicto y no pisa el cambio previo.
