# Capacidad: Trazabilidad OpenSpec

## ADDED Requirements

### Requirement: Detectar cambios OpenSpec
El sistema SHALL cumplir el comportamiento indicado para detectar cambios openspec.

#### Scenario: Caso verificable 1
- **GIVEN** repositorio vinculado
- **WHEN** cambia archivo en rutas OpenSpec configuradas
- **THEN** se indexa ruta, tipo, commit SHA, hash y estado de parseo.

### Requirement: Visualización de artefactos
El sistema SHALL cumplir el comportamiento indicado para visualización de artefactos.

#### Scenario: Caso verificable 2
- **GIVEN** proposal/spec/design/tasks indexado
- **WHEN** se consulta en EMP
- **THEN** se muestra metadata y vista segura sin transformarlo en fuente autoritativa.

### Requirement: Asociar solicitud y change
El sistema SHALL cumplir el comportamiento indicado para asociar solicitud y change.

#### Scenario: Caso verificable 3
- **GIVEN** work item y cambio OpenSpec existentes en mismo tenant
- **WHEN** se vinculan
- **THEN** la relación tiene trazabilidad y se evita vínculo entre tenants.

### Requirement: Checklist
El sistema SHALL cumplir el comportamiento indicado para checklist.

#### Scenario: Caso verificable 4
- **GIVEN** tasks.md con checklist
- **WHEN** se analiza
- **THEN** se reflejan tareas y completion sin crear work items duplicados automáticamente.

### Requirement: Reordenamiento
El sistema SHALL cumplir el comportamiento indicado para reordenamiento.

#### Scenario: Caso verificable 5
- **GIVEN** checklist reordenado
- **WHEN** no se puede reconciliar identidad de ítem con certeza
- **THEN** se registra ambigüedad y requiere intervención, sin marcar otra tarea como hecha.

### Requirement: Edición segura
El sistema SHALL cumplir el comportamiento indicado para edición segura.

#### Scenario: Caso verificable 6
- **GIVEN** usuario en UI
- **WHEN** intenta escribir archivos de OpenSpec directamente al repositorio
- **THEN** se bloquea salvo flujo explícito de PR/approval habilitado.
