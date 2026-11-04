# Capacidad: postgresql-tenancy-foundation

## ADDED Requirements

### Requirement: RLS obligatorio y contexto seguro
The system MUST enforce PostgreSQL row-level security for organization-scoped data and MUST deny access when a valid organization context is absent.

#### Scenario: RLS obligatorio y contexto seguro
- **GIVEN una conexión reutilizada**
- **WHEN** no hay organización establecida
- **THEN** toda lectura/escritura organizacional es denegada

### Requirement: Módulos desacoplados
The system MUST prevent modules from importing another module's internal infrastructure and MUST enforce module boundaries in continuous integration.

#### Scenario: Módulos desacoplados
- **GIVEN un módulo importando infraestructura ajena**
- **WHEN** corre CI
- **THEN** falla la verificación de arquitectura

### Requirement: Rol sin bypass
The database runtime role MUST NOT own tenant tables, have BYPASSRLS, or have privileges to disable row-level security.

#### Scenario: Rol sin bypass
- **GIVEN usuario runtime**
- **WHEN** intenta deshabilitar RLS
- **THEN** se rechaza por privilegios
