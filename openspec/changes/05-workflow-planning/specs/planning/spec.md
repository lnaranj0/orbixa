# Capacidad: Workflows y planificación

## ADDED Requirements

### Requirement: Flujo continuo
El sistema SHALL cumplir el comportamiento indicado para flujo continuo.

#### Scenario: Caso verificable 1
- **GIVEN** proyecto Kanban
- **WHEN** un item cambia de estado permitido
- **THEN** se valida transición y reglas de WIP.

### Requirement: Ciclos opcionales
El sistema SHALL cumplir el comportamiento indicado para ciclos opcionales.

#### Scenario: Caso verificable 2
- **GIVEN** proyecto de modo sprint o híbrido
- **WHEN** se crea ciclo
- **THEN** se definen fechas, objetivo y capacidad sin exigir que todas las tareas entren a ciclo.

### Requirement: Transiciones controladas
El sistema SHALL cumplir el comportamiento indicado para transiciones controladas.

#### Scenario: Caso verificable 3
- **GIVEN** un estado origen sin transición configurada al destino
- **WHEN** se solicita el cambio
- **THEN** se rechaza y audita intento relevante.

### Requirement: Cierre y reapertura
El sistema SHALL cumplir el comportamiento indicado para cierre y reapertura.

#### Scenario: Caso verificable 4
- **WHEN** se ejecuta el caso siguiente
- **THEN** se verifica: Dada tarea hecha, cuando se reabre con permisos, entonces se preservan todas las entradas de historial y métricas de flujo.

### Requirement: Métricas
El sistema SHALL cumplir el comportamiento indicado para métricas.

#### Scenario: Caso verificable 5
- **WHEN** se ejecuta el caso siguiente
- **THEN** se verifica: Dadas marcas de tiempo por transiciones, cuando se consulta reporte, entonces calcula lead time, cycle time, throughput y aging sin contar estados cancelados como entrega.
