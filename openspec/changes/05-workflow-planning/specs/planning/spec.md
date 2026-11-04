# Capacidad: Workflows y planificación

## ADDED Requirements

### Requirement: Flujo continuo
El sistema SHALL permitir que un proyecto trabaje en flujo continuo, sin ciclos, validando en cada cambio de estado la transición configurada y el límite WIP del estado destino.

#### Scenario: Transición permitida en Kanban
- **GIVEN** un proyecto en modo continuo con su workflow
- **WHEN** un item cambia a un estado con transición configurada y WIP disponible
- **THEN** el cambio se aplica, queda en el historial y el item no necesita pertenecer a ningún ciclo

#### Scenario: Límite WIP
- **GIVEN** un estado con límite WIP alcanzado
- **WHEN** se intenta mover otro item a ese estado, también de forma concurrente
- **THEN** se rechaza con 422 sin revelar contadores, y nunca se supera el límite

### Requirement: Ciclos opcionales
El sistema SHALL permitir ciclos con fechas, objetivo y capacidad opcional en proyectos de modo sprint o híbrido, sin exigir que todas las tareas entren a un ciclo, y MUST aceptar en un ciclo solo items del mismo proyecto, con como máximo un ciclo abierto por item.

#### Scenario: Crear ciclo
- **GIVEN** un proyecto de modo sprint o híbrido y `planning.manage`
- **WHEN** se crea un ciclo
- **THEN** se definen fechas, objetivo y capacidad sin exigir que todas las tareas entren al ciclo

#### Scenario: Item de otro proyecto
- **GIVEN** un ciclo del proyecto A
- **WHEN** se intenta añadir un item del proyecto B o de otra organización
- **THEN** se rechaza con el mismo 422 que un item inexistente

### Requirement: Transiciones controladas
El sistema SHALL aplicar el workflow configurado del proyecto y MUST rechazar y auditar las transiciones no configuradas. La configuración MUST NOT ampliar los permisos de `WorkGuard`, y cualquier transición a la categoría `done` MUST exigir `work.approve`.

#### Scenario: Transición no configurada
- **GIVEN** un estado origen sin transición configurada al destino
- **WHEN** un actor con permiso solicita el cambio
- **THEN** se rechaza con 422 y se audita el intento

#### Scenario: Configuración que intenta escalar
- **GIVEN** un project manager que configura el workflow
- **WHEN** intenta habilitar una transición para viewer o reviewer, o quitar la aprobación de una transición a `done`
- **THEN** la configuración se rechaza

#### Scenario: Asignado intenta cerrar
- **GIVEN** un asignado sin `work.approve`
- **WHEN** intenta mover su item a un estado de categoría `done`
- **THEN** recibe 403 y el item no cambia

### Requirement: Cierre y reapertura
El sistema SHALL preservar todo el historial de estados y las métricas de flujo al reabrir un item, y al cerrar un ciclo SHALL registrar explícitamente los items no terminados.

#### Scenario: Reapertura
- **GIVEN** una tarea hecha
- **WHEN** se reabre con permisos y vuelve a terminarse
- **THEN** se conservan todas las entradas del historial y las métricas usan la última llegada a `done`

#### Scenario: Cierre de ciclo
- **GIVEN** un ciclo activo con items terminados y pendientes
- **WHEN** se cierra
- **THEN** los terminados quedan como entregados, los pendientes como no terminados y pueden pasar al siguiente ciclo con referencia de origen

### Requirement: Métricas
El sistema SHALL calcular lead time, cycle time, throughput y aging desde el historial real de transiciones, sin contar los cancelados como entrega ni medir productividad individual, y MUST calcularlas solo sobre items visibles para quien consulta.

#### Scenario: Cálculo desde historial
- **GIVEN** marcas de tiempo por transiciones
- **WHEN** se consulta el reporte
- **THEN** calcula lead time, cycle time, throughput y aging sin contar estados cancelados como entrega

#### Scenario: Métricas sin fuga
- **GIVEN** un miembro que no ve algunos proyectos, o un visor con grants de monitoreo
- **WHEN** consulta métricas
- **THEN** solo incluyen items de su alcance; con alcance vacío obtiene vacío, nunca totales globales
