---
name: openspec-implement
description: Implementa o valida un cambio OpenSpec de Orbixa; invocar cuando el usuario indique un change-id.
---

# Procedimiento

1. Leer `openspec/config.yaml` y todos los artefactos del cambio solicitado.
2. Ejecutar `openspec validate <change-id> --strict` y presentar plan, alcance y criterios.
3. Implementar solo el cambio indicado; preservar contratos y módulos.
4. Ejecutar tests específicos, revisar diff y registrar evidencia por tarea.
5. No archivar ni fusionar cambios sin autorización.
