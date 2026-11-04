# Propuesta: Gateway y ejecución supervisada de agentes

## Motivación
Implementar una porción verificable del gestor multi-organización sin desplegar el cambio maestro completo.

## Alcance
Gateway y ejecución supervisada de agentes. Módulos responsables: Automation, Agents.

## Dependencias previas
- `08-agent-registry`
- `06-github-integration`
- `00-platform-foundation`

## Fuera de alcance
No implementar funcionalidades de fases posteriores ni permisos implícitos entre organizaciones. Los repositorios y OpenSpec continúan siendo fuentes de verdad externas.

## Criterio de terminación
Ningún agente ejecuta acciones privilegiadas sin autorización explícita y verificable.
