# Capacidad: Integración híbrida con GitHub

## ADDED Requirements

### Requirement: Instalación autorizada
El sistema SHALL permitir que un miembro con `integrations.github.manage` vincule una instalación de la GitHub App a su organización solo tras confirmar, con la App y con la autorización del usuario en GitHub, que la instalación existe y que quien vincula tiene acceso a ella; MUST asociar cada instalación a una única organización e indexar solo los repositorios seleccionados explícitamente.

#### Scenario: Vinculación válida
- **GIVEN** un miembro con `integrations.github.manage`
- **WHEN** completa la instalación con un `state` válido y GitHub confirma su acceso
- **THEN** la instalación queda vinculada a su organización y solo se indexan los repositorios que seleccione

#### Scenario: Instalación ajena o reclamada dos veces
- **GIVEN** una instalación sin acceso del usuario, un `state` reutilizado, caducado o de otra sesión, o una instalación ya vinculada a otra organización
- **WHEN** se intenta vincular
- **THEN** se rechaza con un error genérico, sin revelar datos de la otra organización

### Requirement: Verificación HMAC
El sistema MUST verificar la firma de cada webhook sobre los bytes crudos del cuerpo antes de procesar o parsear el payload, y MUST rechazar cuerpos que excedan el tamaño máximo.

#### Scenario: Firma inválida
- **GIVEN** un webhook entrante
- **WHEN** la firma falta, está mal formada o no coincide con el secreto vigente ni con el anterior
- **THEN** se rechaza con 401 antes de parsear el payload y no queda nada guardado

### Requirement: Idempotencia
El sistema SHALL aceptar entregas repetidas sin aplicar sus efectos dos veces, incluidas las reenviadas con otro identificador o en desorden.

#### Scenario: Entrega repetida
- **GIVEN** el mismo `delivery_id` reenviado, o el mismo cuerpo con un `delivery_id` nuevo
- **WHEN** llega por segunda ocasión
- **THEN** se devuelve una aceptación apropiada sin aplicar dos veces los efectos

#### Scenario: Eventos desordenados
- **GIVEN** un evento antiguo que llega después de uno más nuevo del mismo recurso
- **WHEN** se procesa
- **THEN** no sobrescribe el estado más reciente

### Requirement: Enrutamiento por organización
El sistema MUST resolver la organización de cada webhook solo a partir de una instalación activa vinculada, nunca desde el payload, y MUST ignorar sin guardar el payload los eventos de instalaciones no vinculadas o de repositorios no seleccionados.

#### Scenario: Instalación no vinculada o repositorio no seleccionado
- **GIVEN** un webhook válido de una instalación sin vincular, revocada, o de un repositorio no seleccionado
- **WHEN** se recibe
- **THEN** responde aceptado como ignorado y no se guarda el payload en ninguna organización

### Requirement: Vínculo PR
El sistema SHALL registrar la relación entre un PR, commit o check y un work item cuando la referencia `KEY-n` corresponde a un proyecto vinculado a ese repositorio, con metadatos mínimos verificables, y MUST NOT vincular items de proyectos no vinculados ni de otra organización.

#### Scenario: Referencia válida
- **GIVEN** un PR con una referencia `KEY-n` válida de un proyecto vinculado al repositorio
- **WHEN** se recibe el evento
- **THEN** se registra la relación por proyecto y organización, con metadatos verificables

#### Scenario: Referencia ajena
- **GIVEN** un PR que cita la clave de un item de un proyecto no vinculado al repositorio, de un proyecto `restricted` no mapeado o de otra organización
- **WHEN** se procesa
- **THEN** no se crea ningún vínculo

### Requirement: No cierre implícito
El sistema MUST NOT cambiar el estado de un work item a partir de eventos de GitHub.

#### Scenario: PR fusionado
- **GIVEN** un PR fusionado que dice "Closes KEY-1"
- **WHEN** se sincroniza
- **THEN** el item conserva su estado y su versión; solo se actualizan los metadatos del vínculo

### Requirement: Recuperación
El sistema SHALL reconciliar los cambios perdidos mediante sincronización paginada por cursor, con reintentos y backoff, respetando los límites de GitHub y sin duplicados.

#### Scenario: Interrupción de webhooks
- **GIVEN** una interrupción de webhooks
- **WHEN** corre la reconciliación paginada
- **THEN** recupera los cambios permitidos respetando límites y sin duplicados

### Requirement: Sincronización segura
El sistema MUST NOT escribir en GitHub en este cambio, y MUST mantener la clave privada, el secreto del webhook y los tokens fuera de la base de datos, del repositorio, de los logs, de los jobs y de las respuestas.

#### Scenario: Sin escritura saliente
- **GIVEN** la escritura saliente desactivada
- **WHEN** ocurre un evento de Orbixa
- **THEN** no se crean ni editan issues ni PR de GitHub

#### Scenario: Secretos no visibles
- **GIVEN** errores de sincronización, jobs fallidos y respuestas de la API
- **WHEN** se inspeccionan logs, `failed_jobs` y respuestas
- **THEN** no aparecen la clave privada, el secreto del webhook ni tokens de GitHub
