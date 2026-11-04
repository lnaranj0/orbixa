# Capacidad: modular-boundaries

## ADDED Requirements

### Requirement: Ownership por dominio
The system MUST assign a single owning module to each database resource and business capability, and other modules MUST access that capability only through published contracts.

#### Scenario: Ownership por dominio
- **GIVEN una migración**
- **WHEN** se declara un recurso
- **THEN** un único módulo es owner y publica contratos

### Requirement: Outbox transaccional
The system MUST persist the corresponding outbox event in the same database transaction as every business mutation that publishes a domain event.

#### Scenario: Outbox transaccional
- **GIVEN una mutación de negocio exitosa**
- **WHEN** se confirma la transacción
- **THEN** se confirma el evento de outbox en la misma transacción
