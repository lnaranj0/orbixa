# Propuesta: Visores read-only con concesiones por usuario, equipo o toda la organización

## Motivación
Implementar una porción verificable del gestor multi-organización sin desplegar el cambio maestro completo.

## Alcance
Visores read-only con concesiones por usuario, equipo o toda la organización. Módulos responsables: Monitoring, Access.

## Dependencias previas
- `01-identity-tenancy`
- `02-teams-portfolio`
- `03-work-backlog`

## Fuera de alcance
No implementar funcionalidades de fases posteriores ni permisos implícitos entre organizaciones. Los repositorios y OpenSpec continúan siendo fuentes de verdad externas.

## Criterio de terminación
Viewer sin grant obtiene cero datos; grants muestran solo el alcance permitido y nunca autorizan escritura.
