# Capacidad: teams-and-portfolio

## ADDED Requirements

### Requirement: Equipos jerárquicos
The system MUST support parent-child team relationships within the same organization and MUST prevent cycles in the team hierarchy.

#### Scenario: Equipos jerárquicos
- **GIVEN dos equipos del mismo tenant**
- **WHEN** se crea una relación que formaría un ciclo
- **THEN** se rechaza

### Requirement: Membresía de equipo sin acceso implícito
The system MUST validate the team hierarchy when members are added or teams are reorganized, and team membership MUST NOT grant access to every project of the organization.

#### Scenario: Membresía de equipo sin acceso implícito
- **GIVEN** un equipo con un subequipo
- **WHEN** se agrega un miembro o se reorganiza la jerarquía
- **THEN** se valida la ausencia de ciclos y el miembro solo accede a los proyectos asignados explícitamente a su equipo

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
