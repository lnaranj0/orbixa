# Proposal — 12-project-management

## Why
Orbixa debe servir también para gestión de proyectos no relacionados con desarrollo. El producto no debe obligar a usar GitHub, OpenSpec, agentes o sprints para planear y supervisar proyectos.

## What Changes
Añadir un módulo `ProjectManagement` para charter, alcance, objetivos, entregables, hitos, línea base, cronograma, dependencias, riesgos, incidencias, decisiones, presupuesto, recursos, avances y cierre, integrado con Portfolio, Work, Monitoring, Requests y Reporting.

## Impact
Reutiliza `projects.project_id` y `work_items`; no crea un segundo sistema de tareas. Proyecto `general`, `software` o `hybrid`. No sustituye ERP ni contabilidad. Cambios de alcance deben enlazarse con solicitudes formales cuando requieran autorización.
