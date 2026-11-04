# Cambio maestro: bootstrap-engineering-platform

## Por qué
La administración de iniciativas, backlog y cambios técnicos está fragmentada entre actividad personal, código, especificaciones, revisiones y equipos. Se requiere una plataforma propia con trazabilidad completa, sin forzar una metodología única.

## Qué cambia
1. Plataforma multi-organización con miembros multi-tenant y jerarquía de equipos, sobre PostgreSQL 18 con RLS.
2. Portafolio, proyectos, iniciativas, issues/subtareas, dependencias, comentarios y auditoría.
3. Workflows configurables y ejecución continua con ciclos opcionales.
4. GitHub App con enlaces selectivos a repositorios, ramas, commits y pull requests.
5. Índice OpenSpec a partir de Git sin mutar automáticamente artefactos fuente.
6. Agentes IA registrables, asignables y auditables, sin autonomía en la etapa inicial.
7. Dashboards de trabajo personal, equipos y portafolio; eventos e integración extensibles.
8. Modularidad estricta en código, contratos, migraciones, eventos y pruebas de arquitectura.
9. Viewer delegado de solo lectura mediante grants específicos a usuario, equipo o toda la organización.

## Alcance MVP (Fase 1)
Tenancy/RBAC, equipos y proyectos, work items, tablero y backlog, actividad, visor por alcance, auditoría, API y pruebas de aislamiento. UI responsive y filtros por organización/proyecto/equipo.

## Fuera de alcance del MVP
Ejecutar agentes, realizar despliegues, reemplazar GitHub, sincronización bidireccional indiscriminada, facturación SaaS, SSO empresarial obligatorio, migración automática de Jira y gestión de tiempos con facturación.

## Impacto
Aplicación nueva con base de datos aislada; integraciones de solo lectura inicialmente y GitHub App con privilegios mínimos. No modificar los sistemas existentes de BlueApp/Corporativo.

## Riesgos y mitigación
- Fuga entre tenants: contexto obligatorio, claves compuestas, PostgreSQL RLS, policies y pruebas de acceso cruzado.
- Escalamiento de visor: acceso denegado por defecto, grants explícitos con vigencia y revocación, filtros centralizados y pruebas de filtración por listados/exportaciones.
- Duplicación y carreras de webhooks: inbox + delivery_id único + reintento idempotente.
- Desalineación Git/OpenSpec: registrar SHA/commit, errores de parseo y reconciliación.
- Crecimiento de alcance: fases y flags de capacidades.

## Métricas de éxito
Cero accesos cruzados en pruebas; trazabilidad de cambios y eventos; capacidad de registrar una solicitud hasta cierre con historial; reconciliación controlada de vinculaciones externas.
