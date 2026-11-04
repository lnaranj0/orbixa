# Propuesta: Equipos/subequipos, proyectos y permisos de portafolio

## Motivación
Implementar una porción verificable del gestor multi-organización sin desplegar el cambio maestro completo.

## Alcance
Equipos/subequipos, proyectos y permisos de portafolio. Módulos responsables: Teams, Portfolio.

## Dependencias previas
- `01-identity-tenancy`

## Fuera de alcance
No implementar funcionalidades de fases posteriores ni permisos implícitos entre organizaciones. Los repositorios y OpenSpec continúan siendo fuentes de verdad externas.

## Criterio de terminación
Un usuario puede estar en varios equipos sin cruzar tenants; jerarquías no contienen ciclos y proyectos respetan confidencialidad.
