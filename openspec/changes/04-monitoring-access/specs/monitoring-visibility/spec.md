# Capacidad: Visibilidad delegada de monitoreo

## ADDED Requirements

### Requirement: Grants explícitos por visor
El sistema SHALL permitir a un administrador autorizado conceder visibilidad de solo lectura a un viewer miembro activo del mismo tenant con scopes `user`, `team` o `organization`, vigencia y razón auditada. SHALL denegar todo acceso al viewer sin grant.

#### Scenario: Visor de una persona
- **GIVEN** viewer V en organización A y grant activo `user` hacia miembro U de A
- **WHEN** V consulta monitoreo
- **THEN** únicamente ve los items donde U es owner o colaborador activo, respetando confidencialidad, y no puede editar.

#### Scenario: Visor de un equipo
- **GIVEN** viewer V con grant `team` sobre T sin `include_descendants`
- **WHEN** V consulta tareas del equipo o sus métricas
- **THEN** ve solo trabajo explícitamente vinculado a T, no subequipos ni tareas ajenas.

#### Scenario: Inclusión opcional de subequipos
- **GIVEN** un grant `team` sobre T con `include_descendants=true`
- **WHEN** un subequipo S pertenece a T
- **THEN** se permite observar trabajo autorizado de S sin elevar privilegios a otros equipos ni proyectos confidenciales.

#### Scenario: Visor de todos
- **GIVEN** viewer V con grant `organization` para organización A
- **WHEN** consulta panel y métricas
- **THEN** ve los elementos estándar de A, sin ver B ni información restringida no autorizada.

### Requirement: Administración, vigencia y revocación
El sistema SHALL permitir crear, modificar y revocar grants solo con `monitoring.grants.manage`, guardando historia de actor, motivo, cambio y fecha. SHALL verificar vigencia durante cada lectura.

#### Scenario: Revocación inmediata
- **GIVEN** viewer V con grant activo y una sesión ya iniciada
- **WHEN** el administrador revoca grant o membresía
- **THEN** cualquier lectura posterior se deniega aunque existan cachés, widgets, búsquedas o tareas en cola.

#### Scenario: Cross-tenant y escalamiento
- **GIVEN** administrador A de organización A
- **WHEN** intenta conceder a viewer de A acceso a usuario/equipo de organización B
- **THEN** la operación se rechaza y se registra intento, sin revelar detalles de B.

#### Scenario: Concesión sin escalada
- **GIVEN** un miembro sin `monitoring.grants.manage`, un administrador que se concede a sí mismo, un destinatario sin `monitoring.view` o un grant `restricted` emitido sin `monitoring.restricted.view`
- **WHEN** se intenta crear o ampliar el grant
- **THEN** se rechaza sin crear ni modificar grants

### Requirement: Protección uniforme de recursos
El sistema SHALL aplicar el mismo alcance a detalle, listados, filtros, búsqueda, contadores, reportes, exportación, archivos y APIs; SHALL ocultar datos confidenciales y prohibir escrituras por rol viewer.

#### Scenario: Prevención de fuga por agregados
- **GIVEN** viewer con grant limitado a usuario U
- **WHEN** solicita conteos, gráficos, búsqueda y exportación
- **THEN** las consultas contienen únicamente registros autorizados, sin totales de terceros ni exportación si carece de `monitoring.export`.

#### Scenario: Viewer intenta mutar tarea
- **GIVEN** viewer con grant aplicable a item I
- **WHEN** invoca PATCH, transición, comentario, aprobación o asignación
- **THEN** se deniega 403, no cambia el item y se audita según política; sobre un item que ningún grant suyo cubre recibe el 404 idéntico de siempre.

#### Scenario: Viewer sin grant
- **GIVEN** un viewer miembro activo de A sin ningún grant vigente
- **WHEN** consulta panel, listados, detalle, equipos o usuarios de monitoreo
- **THEN** obtiene cero datos: listados vacíos, conteos en cero y 404 en cualquier detalle

#### Scenario: Cursor tras revocación
- **GIVEN** un viewer paginando con un cursor emitido bajo un grant
- **WHEN** el grant se revoca o expira y usa el cursor
- **THEN** el cursor se rechaza y la siguiente lectura refleja el nuevo alcance

### Requirement: Implementación modular
El sistema SHALL mantener Monitoring como módulo separado de Access, Work, Portfolio y Teams; consumirá interfaces declaradas y consultas autorizadas, no repositorios internos de otros módulos.

#### Scenario: Verificación de límites en CI
- **GIVEN** cambio que introduce una importación o consulta directa a repositorio privado de otro módulo
- **WHEN** corre el análisis de dependencias y tests de contrato
- **THEN** el pipeline falla hasta reemplazarlo con contrato aprobado.
