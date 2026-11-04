# Capacidad: agent-execution

## ADDED Requirements

### Requirement: Aprobación humana
The system MUST require explicit approval by an authorized human before any agent performs an action designated as approval-gated.

#### Scenario: Aprobación humana
- **GIVEN un agente sin aprobación**
- **WHEN** intenta ejecutar una acción privilegiada
- **THEN** el gateway rechaza la operación

### Requirement: Aislamiento de runner
The system MUST execute autonomous agent jobs in isolated runners with per-job credentials and least-privilege permissions.

#### Scenario: Aislamiento de runner
- **GIVEN dos ejecuciones de organizaciones diferentes**
- **WHEN** corren simultáneamente
- **THEN** no comparten tokens, archivos ni datos

### Requirement: Revocación
The system MUST revoke agent access or monitoring permissions immediately after an authorized revocation and MUST audit that action.

#### Scenario: Revocación
- **GIVEN una autorización revocada**
- **WHEN** un agente intenta reanudar
- **THEN** el gateway deniega y audita
