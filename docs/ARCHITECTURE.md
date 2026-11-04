# Arquitectura funcional

## Funciones y límites de dominio
1. **Identity/Tenancy**: usuarios globales, identidades, membresía por tenant, invitaciones, roles y acceso soporte temporal.
2. **Teams**: árbol de equipos/subequipos y membresía de personas; un individuo puede pertenecer a varios equipos.
3. **Portfolio**: proyectos, repositorios, cartera de iniciativas, hitos, estado y visibilidad por equipo.
4. **Work**: catálogo de work items, jerarquías, asignaciones, dependencias, prioridad, etiquetas, comentarios, adjuntos, actividad.
5. **Workflow**: estados configurables y transición con permisos, guardas, requisitos y WIP.
6. **Planning**: modos continuo/sprint/híbrido, ciclos opcionales, capacidad y calendario.
7. **Specs**: catálogo de cambios y artefactos OpenSpec, vínculo a requisitos, checklist indexado y validez por SHA.
8. **Integrations**: GitHub App, mapeo repos, PR/commits, webhooks, reconciliación, retry.
9. **Agents**: identidad IA, capacidades, asignaciones, políticas y ejecuciones externas.
10. **Monitoring/Visibility**: concesiones explícitas a visores sobre usuario, equipo o toda la organización; vistas de seguimiento y métricas filtradas por scope.
11. **Audit/Notifications/Reporting**: historial append-only, eventos, outbox/inbox, indicadores y alertas.

## Fuentes de verdad
| Recurso | Fuente de verdad | EMP almacena |
| --- | --- | --- |
| Backlog, workflow, prioridad, owner | EMP PostgreSQL | Datos completos |
| Repositorios, PR, commits y revisiones | GitHub | Identificadores, metadatos y vínculo |
| OpenSpec proposals/specs/design/tasks | Git en el repo | Índice derivado, hashes, estado y vínculos |
| Agente externo y ejecución | Runner externo (fase futura) | Registro, política, estado y evidencia |
| Membresía y roles EMP | EMP | Datos completos |

## Flujos clave
**Captura**: intake -> clasificación -> priorización -> ready -> desarrollo -> revisión -> done, con estados configurables y cancelación/reapertura.
**Cambio grande**: iniciativa -> OpenSpec proposal -> design/specs -> checklist -> tasks vinculadas -> PR -> validación -> release -> archivo.
**Agente fase 3**: agente registrado -> asignación -> ejecución reportada externamente -> review humano -> cierre. No se dispara ejecución desde EMP.

## UI
Shell multi-organización; selector tenant, proyectos/equipos, navegación Mi trabajo, Backlog, Tablero, Ciclos, Specs, Repositorios, Agentes, Métricas, Ajustes. Responsive móvil; accesibilidad WCAG AA objetivo; panel lateral de work item; búsqueda + shortcuts; evitar dashboards llenos de tarjetas sin valor operativo.

## NFR sugeridos (validar con operación)
- p95 de consultas comunes <500 ms en entorno objetivo con dataset de prueba; no es compromiso SLA.
- 0 lecturas/escrituras cross-tenant en suite de seguridad.
- Webhook respondido rápido (2xx tras persistir inbox, no tras procesar).
- Recuperación de eventos con reintento y reconciliación manual/programada.
- Tiempos UTC, TZ por usuario, logs estructurados con correlation ID.
- Integraciones deben soportar pérdida temporal de proveedor y credenciales revocadas.

## Modularidad obligatoria
- Monolito modular desde fase 0. Módulos: IdentityTenancy, Access, Teams, Portfolio, Work, Workflow, Planning, Specs, GitHubIntegration, Monitoring, Agents, Audit, Notifications, Reporting; Automation reservado.
- Estructura recomendada por módulo: `Domain/`, `Application/`, `Infrastructure/`, `Presentation/`, `Contracts/`, `Database/Migrations/`, `Tests/`. Sin tablas o repositorios ajenos llamados directamente desde controladores de otro módulo.
- Comunicación entre módulos mediante interfaces y eventos versionados, ownership de tablas, tests de contrato, y composición de servicios en container Laravel. El core no importa implementaciones específicas de GitHub o de un agente.
- No crear módulos solo nominales: las dependencias y migraciones deberán respetar sus límites. Bounded contexts verificables en revisión y CI.

## Visibilidad delegada para monitoreo
- El rol `viewer` es de solo lectura; por sí solo **no otorga acceso a todo el tenant**. Se autoriza una consulta cuando el usuario es miembro activo de la organización y existe un grant de monitoreo vigente y aplicable, además de superar los límites de confidencialidad del proyecto/recurso.
- Grants explícitos: `user` (trabajo asociado al usuario observado como owner/assignee/participante configurado), `team` (trabajo de un equipo, subequipos solo con `include_descendants=true`), `organization` (todo el trabajo no restringido de la organización). Un viewer puede recibir varios grants; se unen sus alcances, sin elevar permisos de escritura.
- Para viewer de usuario, resolver asociaciones al recurso por reglas consistentes y documentadas, sin incluir automáticamente todos los proyectos en los que esa persona participa. Para viewer de equipo, definir si se incluyen trabajos de miembros fuera de proyectos del equipo (por defecto no).
- El nivel de confidencialidad del recurso (publico-interno/restringido) puede limitar el alcance de un grant; los recursos restringidos requieren autorización explícita adicional. Ningún grant permite leer secretos, tokens, credenciales, mensajes privados o auditoría sensible.
- Listados, métricas, búsquedas, exportaciones, APIs, eventos publicados a un feed, adjuntos y notificaciones aplican exactamente el mismo predicado central de visibilidad; revocación invalida cachés y suscripciones.
- Un viewer no gestiona grants ni ve el inventario de identidades fuera de su alcance. El grant se administra por roles autorizados y queda auditado.


## Requests & RequirementDocuments (v4)
- `Requests` recibe peticiones de desarrollo/cambios de reglas, mantiene identidad de solicitante vs capturista, evaluación y estado formal del pedido. `ADDR` es tipo configurable, pendiente de definición de negocio.
- `RequirementDocuments` versiona hojas de requerimiento y evidencia de conformidad (PDF, hash, archivo escaneado, firmante, fecha) sin sobrescritura.
- No confundir petición y work item: una solicitud puede vincular múltiples work items/changes OpenSpec mediante contratos públicos, y un work item puede referenciar una o más solicitudes.
- Los documentos formalizados mantienen snapshot, clasificación y permisos propios; Monitoring no hereda acceso a firmas o datos personales.
- Añadir navegación `Solicitudes`, `Por firmar` y `Mis solicitudes`.

## Orbixa v5: división de dominios
`ProjectManagement` es un bounded context nuevo sobre la misma identidad `Portfolio.Project` y las mismas actividades `Work.WorkItem`; no construir un gestor paralelo. `Engineering` agrega capacidades opcionales. `DesignSystem` es una dependencia transversal de presentación, sin dependencia de dominio. Ver `docs/PRODUCT_SCOPE.md` y `docs/DESIGN_SYSTEM.md`.
