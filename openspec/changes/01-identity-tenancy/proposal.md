# Propuesta: Organizaciones, usuarios, membresías e invitaciones

## Motivación
Implementar una porción verificable del gestor multi-organización sin desplegar el cambio maestro completo.

## Alcance
Organizaciones, usuarios, membresías e invitaciones. Módulos responsables: IdentityTenancy, Access.

## Dependencias previas
- `00-platform-foundation`

## Fuera de alcance
No implementar funcionalidades de fases posteriores ni permisos implícitos entre organizaciones. Los repositorios y OpenSpec continúan siendo fuentes de verdad externas.

## Criterio de terminación
Sin membresía activa no hay acceso; las políticas y RLS bloquean accesos cruzados aun con identificadores válidos.
