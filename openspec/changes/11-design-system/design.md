# Design — DesignSystem

## Decisiones
- Módulo `DesignSystem` sin lógica de negocio; componentes Blade `x-ui.*`, Livewire únicamente cuando haya estado de servidor.
- `resources/css/tokens.css` con variables semánticas; Tailwind utilities sobre tokens; tema claro y oscuro.
- Aplicación accesible con menú lateral azul marino, barra superior y área de contenido clara.
- Listados con toolbar de búsqueda/filtros, orden/paginación por servidor, badges de estado y acciones accesibles.
- API de props documentada para los componentes; evitar consumo directo de modelos Eloquent dentro de componentes presentacionales.
- Validación visual por viewport 375, 768, 1280 y 1600 px; pruebas de teclado, focus y contraste.

## Contratos
`ui.page-header`, `ui.data-table`, `ui.status-badge`, `ui.filter-bar`, `ui.modal`, `ui.form-field`, `ui.timeline` y shell global. Parámetros tipados/documentados; estados loading/error/empty/read-only.

## Seguridad
La presentación jamás sustituye Policies/RLS. Acciones ocultas por permisos también se deben bloquear en backend; componentes no almacenan secretos.

## Referencia visual
`docs/DESIGN_SYSTEM.md`; captura original entregada por usuario en conversación, no incluida como licencia o asset de la aplicación.
