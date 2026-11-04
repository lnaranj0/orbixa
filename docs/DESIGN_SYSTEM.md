# Orbixa Design System — TailAdmin-inspired

## Propósito
Sistema visual **obligatorio y transversal** para todos los módulos de Orbixa. Referencia proporcionada: captura del panel "privado" de eventos de webhook. Inspiración estructural TailAdmin, sin copiar marcas ni depender de componentes de licencia comercial.

## Stack y arquitectura
- Laravel Blade + Livewire + Alpine.js + Tailwind CSS.
- Componentes Blade (`x-ui.*`) como base; componentes Livewire para estado de servidor, paginación, filtros y formularios reactivos; Alpine para interacciones locales simples.
- CSS con tokens semánticos y variables de temas. Prohibido codificar colores específicos repetidos por módulo.
- DesignSystem es capa compartida de presentación; no puede depender del dominio ProjectManagement o Engineering.

## Layout de referencia
- Sidebar izquierda azul marino, secciones jerarquizadas, submenús, estado activo y modo colapsado.
- Topbar con breadcrumb, atajos, búsqueda global, notificaciones y selector de organización.
- Zona de contenido gris azulado suave y superficies blancas con esquinas redondeadas.
- Cabeceras con título, subtítulo/contexto y acciones primarias alineadas a la derecha.
- Tablas dentro de tarjeta con barra de filtros, segmentados, búsqueda, badges de estado, separadores sutiles, hover, paginación y acciones por fila.
- Visualización responsive: sidebar drawer en móvil; evitar que una tabla compleja cause desplazamiento horizontal de toda la página (overflow localizado).

## Tokens iniciales (ajustables después de aprobación visual)
- `--color-sidebar: #14213b`; `--color-page: #eef3f9`; `--color-surface: #ffffff`.
- `--color-ink: #172d52`; `--color-muted: #60718d`; `--color-border: #dbe4ef`.
- `--color-primary: #244f95`; estados: success, warning, danger, info con fondo suave y contraste WCAG.
- Espacios: escala Tailwind; radio base `rounded-xl`, contenedores `rounded-2xl`; shadow muy discreto.
- Modo claro y oscuro con equivalentes semánticos; preferencia persistida por usuario.

## Componentes base
`AppShell`, `Sidebar`, `Topbar`, `Breadcrumb`, `PageHeader`, `OrganizationSwitcher`, `StatsCard`, `Panel`, `DataTable`, `FilterToolbar`, `SearchInput`, `StatusBadge`, `Avatar`, `Tabs/SegmentedControl`, `Dropdown`, `Drawer`, `Modal`, `FormField`, `DatePicker`, `FileUploader`, `Timeline`, `Stepper`, `EmptyState`, `Skeleton`, `Toast`, `ConfirmDialog`, `PermissionGate`.

## Vistas base que el DesignSystem debe ejemplificar
Dashboard general; listado portafolio; ficha de proyecto con resumen/hitos/riesgos; backlog; hoja de solicitud para firma; vista de monitoreo (solo lectura); detalle de actividad; formulario de proyecto.

## Reglas UX
- Un patrón único de búsqueda, filtros, estado y paginación; URL sincronizada para filtros compartibles.
- Priorizar lectura rápida y acciones claras; no usar badges para información irrelevante.
- Foco visible y navegación teclado; controles con labels; WCAG 2.2 AA como objetivo.
- Preparar localización española (`es-MX`), fechas en zona de la organización y almacenamiento UTC.
- Mostrar estados de carga, error, vacío, acceso denegado, confirmación de acciones irreversibles.
- Tablas pequeñas en móvil pueden usar scroll horizontal local; no transformar automáticamente todo en tarjetas.
- Rendimiento: paginación de servidor, consultas acotadas, carga diferida de paneles, sin dependencias JS excesivas.

## Gobernanza
Toda feature OpenSpec debe indicar componentes reutilizados y cambios necesarios al catálogo. Una revisión de diseño y accesibilidad es condición de aceptación; no duplicar componentes por dominio.
