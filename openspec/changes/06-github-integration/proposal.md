# Propuesta: GitHub App, webhooks idempotentes y reconciliación

## Motivación
Implementar una porción verificable del gestor multi-organización sin desplegar el cambio maestro completo.

## Alcance
GitHub App, webhooks idempotentes y reconciliación. Módulos responsables: GitHubIntegration.

## Dependencias previas
- `03-work-backlog`
- `00-platform-foundation`

## Fuera de alcance
No implementar funcionalidades de fases posteriores ni permisos implícitos entre organizaciones. Los repositorios y OpenSpec continúan siendo fuentes de verdad externas.

## Criterio de terminación
Un PR se vincula con una tarea sin cerrarla automáticamente; duplicados no producen doble efecto y secretos no son visibles.
