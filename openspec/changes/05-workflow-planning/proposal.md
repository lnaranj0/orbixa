# Propuesta: Estados configurables, ciclos opcionales y métricas de flujo

## Motivación
Implementar una porción verificable del gestor multi-organización sin desplegar el cambio maestro completo.

## Alcance
Estados configurables, ciclos opcionales y métricas de flujo. Módulos responsables: Workflow, Planning, Reporting.

## Dependencias previas
- `03-work-backlog`

## Fuera de alcance
No implementar funcionalidades de fases posteriores ni permisos implícitos entre organizaciones. Los repositorios y OpenSpec continúan siendo fuentes de verdad externas.

## Criterio de terminación
Proyectos con flujo continuo funcionan sin sprint; transiciones ilegales fallan y métricas no filtran otros alcances.
