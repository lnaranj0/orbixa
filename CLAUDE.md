# Orbixa — Project & Engineering Management

## Contexto y fuente de verdad
- Producto: Orbixa. Laravel 13, PHP 8.3+, PostgreSQL 18, Predis/Redis, Blade + Livewire + Alpine + Tailwind, UI inspirada en TailAdmin.
- La documentación de arquitectura vive en `docs/`; los cambios y requisitos formales en `openspec/`.
- **Nunca implementes un cambio sin leer su `proposal.md`, `design.md`, `tasks.md` y todos los `specs/**/spec.md`.** Valida el cambio con la CLI antes y después.
- Trabaja solo en el cambio OpenSpec solicitado. No anticipes otros módulos.
- Si docs, spec y código se contradicen, detente y explica la discrepancia antes de elegir.

## Arquitectura
- Monolito modular: Domain, Application, Infrastructure, Presentation, Contracts, Tests; no importaciones entre internals de módulos ajenos.
- Módulos de Project Management y Engineering son capacidades diferentes sobre proyectos/actividades compartidos. GitHub y OpenSpec son opcionales para proyectos generales.
- Toda entidad tenant-scoped necesita `organization_id`, validaciones en Laravel y RLS efectivo en PostgreSQL; probar fugas entre organizaciones con rol real de runtime sin BYPASSRLS.
- Separar credencial DB de migraciones (propietaria) de credencial runtime (no propietaria). No usar superusuario para la aplicación.
- Cambios en contratos, migraciones, permisos y RLS requieren revisión explícita.
- Cada módulo posee sus datos y expone contratos/eventos. Outbox transaccional para eventos que deban publicarse confiablemente.
- El sistema de visores permite alcance sobre usuario, equipo (descendientes solo si autorizado) u organización, siempre de solo lectura y dentro del tenant.
- Solicitudes de cambio/ADDR, hojas de requerimiento, firmas, revisiones y evidencias son versionadas y auditables.

## UI y calidad
- Seguir el DesignSystem Orbixa (referencia TailAdmin): sidebar marino, tarjetas claras, bordes suaves, tablas limpias, móvil accesible, modo claro/oscuro.
- No improvisar componentes visuales exclusivos por módulo cuando exista uno compartido.
- No declarar éxito sin pruebas: PHPUnit/Pest según configuración, lint, build frontend, pruebas multi-tenant y aceptación OpenSpec.
- No usar `migrate:fresh`, `db:wipe`, borrados, force push ni acciones destructivas sin autorización humana explícita.
- No almacenar secretos en Git ni imprimir valores sensibles en logs o respuestas.

## Git y responsabilidad
- Identidad autor/committer de Git: la persona o cuenta de servicio realmente configurada y autorizada; **no falsificar autores**.
- No agregar créditos automáticos, `Co-Authored-By: Claude`, firmas promocionales, metadatos de sesiones ni mensajes de IA a commits/PR. La configuración `.claude/settings.json` elimina las atribuciones automáticas de Claude Code.
- No crear commits ni hacer push sin autorización explícita del usuario, aunque se hayan aprobado ediciones de código.
- Respetar cualquier obligación de divulgación de uso de IA establecida por la organización, cliente o repositorio.

## Ejecución
1. Revisar estado Git y OpenSpec, identificar archivos afectados.
2. Planificar contratos, migraciones, seguridad, tests y UI; pedir aprobación antes de cambios sensibles.
3. Implementar incremento pequeño, testear, revisar diff; actualizar `tasks.md` solo con evidencia.
4. Reportar comandos realmente ejecutados, resultados, riesgos y pendientes; nunca inventar salidas.
