---
name: postgres-rls
description: Diseña y verifica aislamiento multi-tenant PostgreSQL 18, roles, migraciones y RLS.
---

# Procedimiento

Usar rol propietario para migraciones y rol runtime no propietario sin BYPASSRLS. Definir contexto tenant de forma segura por transacción, impedir fugas entre conexiones reutilizadas, probar deny-by-default para tenant ausente y acceso cruzado. Revisar SQL parametrizado, índices y FK compuestas. Nunca ejecutar operaciones destructivas sin autorización.
