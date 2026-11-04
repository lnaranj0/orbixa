# Capacidad: Registro y actividad de agentes IA

## ADDED Requirements

### Requirement: Registro de agentes
El sistema SHALL cumplir el comportamiento indicado para registro de agentes.

#### Scenario: Caso verificable 1
- **GIVEN** admin con permisos
- **WHEN** registra agente con proveedor, capacidades y estado
- **THEN** queda restringido a su organización.

### Requirement: Asignación de agentes
El sistema SHALL cumplir el comportamiento indicado para asignación de agentes.

#### Scenario: Caso verificable 2
- **GIVEN** agente habilitado y work item compatible
- **WHEN** se asigna como colaborador
- **THEN** conserva owner y aprobador humanos cuando la política lo exige.

### Requirement: Importar resultados
El sistema SHALL cumplir el comportamiento indicado para importar resultados.

#### Scenario: Caso verificable 3
- **GIVEN** sistema autenticado
- **WHEN** reporta ejecución con external_run_id
- **THEN** crea o actualiza estado sin duplicación y conserva evidencia/auditoría.

### Requirement: Ejecución bloqueada
El sistema SHALL cumplir el comportamiento indicado para ejecución bloqueada.

#### Scenario: Caso verificable 4
- **WHEN** se ejecuta el caso siguiente
- **THEN** se verifica: Dada Fase 1 activa, cuando se pide lanzar código autónomamente desde EMP, entonces no existe acción permitida y se devuelve error de capacidad deshabilitada.

### Requirement: Revocación
El sistema SHALL cumplir el comportamiento indicado para revocación.

#### Scenario: Caso verificable 5
- **GIVEN** agente deshabilitado
- **WHEN** se intenta nueva asignación o recepción de acciones activas
- **THEN** se aplica política de denegación.
