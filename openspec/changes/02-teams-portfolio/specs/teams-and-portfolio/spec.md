# Capacidad: teams-and-portfolio

## ADDED Requirements

### Requirement: Equipos jerárquicos
The system MUST support parent-child team relationships within the same organization and MUST prevent cycles in the team hierarchy.

#### Scenario: Equipos jerárquicos
- **GIVEN dos equipos del mismo tenant**
- **WHEN** se crea una relación que formaría un ciclo
- **THEN** se rechaza

### Requirement: Proyectos aislados
The system MUST isolate each project by organization and MUST reject access by users or teams without appropriate authorization.

#### Scenario: Proyectos aislados
- **GIVEN un usuario de otra organización**
- **WHEN** intenta consultar un proyecto por UUID
- **THEN** no recibe datos

### Requirement: Asignación de equipos
The system MUST support assigning authorized teams to projects while preserving organization boundaries and a traceable assignment history.

#### Scenario: Asignación de equipos
- **GIVEN miembros de diferentes equipos**
- **WHEN** se asocia un proyecto
- **THEN** solo autorizados pueden leerlo
