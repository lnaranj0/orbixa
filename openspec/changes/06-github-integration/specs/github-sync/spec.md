# Capacidad: Integración híbrida con GitHub

## ADDED Requirements

### Requirement: Instalación autorizada
El sistema SHALL cumplir el comportamiento indicado para instalación autorizada.

#### Scenario: Caso verificable 1
- **GIVEN** owner del tenant
- **WHEN** vincula una instalación GitHub App a un repositorio autorizado
- **THEN** solo se indexan repos seleccionados.

### Requirement: Verificación HMAC
El sistema SHALL cumplir el comportamiento indicado para verificación hmac.

#### Scenario: Caso verificable 2
- **GIVEN** webhook entrante
- **WHEN** firma no coincide con secreto
- **THEN** se rechaza antes de procesar payload.

### Requirement: Idempotencia
El sistema SHALL cumplir el comportamiento indicado para idempotencia.

#### Scenario: Caso verificable 3
- **GIVEN** el mismo delivery_id redelivered
- **WHEN** llega por segunda ocasión
- **THEN** se devuelve aceptación apropiada sin aplicar dos veces los efectos.

### Requirement: Vínculo PR
El sistema SHALL cumplir el comportamiento indicado para vínculo pr.

#### Scenario: Caso verificable 4
- **GIVEN** PR con referencia EMP válida
- **WHEN** se recibe evento
- **THEN** se registra relación por proyecto/tenant y metadatos verificables.

### Requirement: No cierre implícito
El sistema SHALL cumplir el comportamiento indicado para no cierre implícito.

#### Scenario: Caso verificable 5
- **GIVEN** PR fusionado
- **WHEN** se sincroniza
- **THEN** no se marca desplegada ni cerrada una tarea salvo regla explícita y guardas satisfechas.

### Requirement: Recuperación
El sistema SHALL cumplir el comportamiento indicado para recuperación.

#### Scenario: Caso verificable 6
- **WHEN** se ejecuta el caso siguiente
- **THEN** se verifica: Dada interrupción de webhooks, cuando corre reconciliación paginada, entonces recupera cambios permitidos respetando límites y sin duplicados.

### Requirement: Sincronización segura
El sistema SHALL cumplir el comportamiento indicado para sincronización segura.

#### Scenario: Caso verificable 7
- **WHEN** se ejecuta el caso siguiente
- **THEN** se verifica: Dada escritura saliente desactivada, cuando un evento de EMP ocurre, entonces no se crean ni editan issues o PR de GitHub.
