# Capacidad: Auditoría y eventos

## ADDED Requirements

### Requirement: Transactional outbox
El sistema SHALL cumplir el comportamiento indicado para transactional outbox.

#### Scenario: Caso verificable 1
- **WHEN** se ejecuta el caso siguiente
- **THEN** se verifica: Dada mutación de negocio confirmada, cuando se guarda, entonces el evento de outbox queda en la misma transacción.

### Requirement: Historial completo
El sistema SHALL cumplir el comportamiento indicado para historial completo.

#### Scenario: Caso verificable 2
- **WHEN** se ejecuta el caso siguiente
- **THEN** se verifica: Dada transición o cambio de responsable, cuando se registra, entonces actor, tenant, timestamps, correlation y estado anterior/nuevo quedan auditables.

### Requirement: Procesamiento repetido
El sistema SHALL cumplir el comportamiento indicado para procesamiento repetido.

#### Scenario: Caso verificable 3
- **GIVEN** evento consumido anteriormente
- **WHEN** worker lo reintenta
- **THEN** handler evita efectos duplicados.

### Requirement: Datos sensibles
El sistema SHALL cumplir el comportamiento indicado para datos sensibles.

#### Scenario: Caso verificable 4
- **GIVEN** evento que incluye tokens o secretos
- **WHEN** se registra
- **THEN** la información sensible se filtra y nunca aparece en logs.

### Requirement: Auditoría de acceso
El sistema SHALL cumplir el comportamiento indicado para auditoría de acceso.

#### Scenario: Caso verificable 5
- **GIVEN** cambio de rol o acceso sensible de soporte
- **WHEN** ocurre
- **THEN** se agrega auditoría de seguridad con justificación, alcance y expiración cuando aplique.
