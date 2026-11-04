# Diseño: 00-platform-foundation

## Contexto y decisiones
- Base de datos: PostgreSQL 18, `uuid` para identificadores, `timestamptz` para eventos y `jsonb` solo para metadatos no relacionales.
- Arquitectura: monolito modular Laravel con capas `Domain`, `Application`, `Infrastructure`, `Presentation`, `Contracts`, `Database/Migrations`, `Tests`.
- Ownership: Platform, Audit. Los módulos externos consumen contratos/eventos, nunca consultas directas a tablas ajenas.
- Los registros organizacionales llevan `organization_id`; comprobaciones de acceso deny-by-default, SQL RLS forzada y validación Laravel.
- Todo evento relevante conserva `event_id`, `organization_id`, `correlation_id`, versión, instante y actor.

## Dependencias
- Ninguna.

## Contrato modular
- Entradas: casos de uso invocados por API/Livewire bajo tenant autenticado; consumers externos mediante interfaces versionadas.
- Salidas: DTOs explícitos y eventos internos transaccionales outbox cuando proceda.
- Seguridad: ninguna consulta, cola, exportación, búsqueda o agregación debe eludir el predicado central de autorización.
- Concurrencia: índices/constraints más validación de dominio; procesamientos repetidos idempotentes.

## Riesgos y decisiones pendientes
- Validar versiones exactas de Laravel, Postgres y extensiones en CI, no asumir soporte por compatibilidad teórica.
- Aterrizar API, índices y migraciones con pruebas de integración reales antes de implementar UI.
- Revisar retención y niveles de confidencialidad antes de introducir exportaciones o adjuntos.

## Verificación de salida
La aplicación inicia, migraciones y pruebas reales de PostgreSQL funcionan; violaciones de límites entre módulos fallan CI; no existe exposición entre tenants.
