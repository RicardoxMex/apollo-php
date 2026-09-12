# WebSockets / Workerman — Ficha de trabajo

> Copia esta carpeta (`docs/_plantilla/`) a `docs/<DDMMYYYY>_<kebab-tema>/` para cada tema nuevo. Convención: `templates/work-folder.md` (`.ai/templates/work-folder.md`).

- **Fecha:** 11092026
- **Tipo:** feature
- **Estado:** en curso
- **Riesgo:** HIGH

| Artefacto | Qué es |
|---|---|
| `spec.md` | Requisitos + criterios de aceptación (fuente de verdad) |
| `plan.md` | Meta L1, hitos L2, tareas L3 + grafo de dependencias |
| `design.md` | Diseño y decisiones (arquitectura, contratos, transporte) |
| `bitacora.md` | Bitácora cronológica: cada tarea completada y checkpoint |
| `tasks/` | Tarjetas de tarea `tasks/<ID>.md` (autoritativo: `store/tasks.json`) |
| `handoffs/` | Documentos de handoff `handoffs/<ID>.md` (metadata: `store/handoffs.json`) |

## Resumen

Sustituir la dependencia de la extensión **OpenSwoole** en el módulo Realtime de Apollo Framework por **Workerman** (paquete Composer, sin extensión nativa), manteniendo la arquitectura existente (`core/Realtime/`: EventBus, ConnectionManager, ChannelManager, NotificationManager, Broadcaster, NotificationRepository) y entregando el spec funcional 24-puntos: autenticación JWT a nivel de conexión, mapeo `user_id→conexiones`, NotificationService de alto nivel, heartbeat, reconexión JS con backoff, persistencia separada de la entrega, seguridad, WSS vía Nginx, Redis preparado pero opcional.
