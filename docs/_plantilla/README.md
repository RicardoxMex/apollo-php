# <Tema> — Ficha de trabajo

> Copia esta carpeta (`docs/_plantilla/`) a `docs/<DDMMYYYY>_<kebab-tema>/` para cada tema nuevo. Convención: `templates/work-folder.md` (`.ai/templates/work-folder.md`).

- **Fecha:** <DDMMYYYY>
- **Tipo:** feature | bugfix | refactor | docs | investigación
- **Estado:** en curso → `archivado` cuando termine (archivo en sitio, no se borra)
- **Riesgo:** LOW | MEDIUM | HIGH | CRITICAL

| Artefacto | Qué es |
|---|---|
| `spec.md` | Requisitos + criterios de aceptación (fuente de verdad) |
| `plan.md` | Meta L1, hitos L2, tareas L3 + grafo de dependencias |
| `design.md` | Diseño y decisiones (solo si el tema lo necesita) |
| `bitacora.md` | Bitácora cronológica: cada tarea completada y checkpoint |
| `tasks/` | Tarjetas de tarea `tasks/<ID>.md` (autoritativo: `store/tasks.json`) |
| `handoffs/` | Documentos de handoff `handoffs/<ID>.md` (metadata: `store/handoffs.json`) |
