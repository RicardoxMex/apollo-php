# Ficha — Módulo Uploads (subida de archivos)

| Campo | Valor |
|---|---|
| Estado | completado (2026-09-10) |
| Fecha | 2026-09-10 |
| Orden | "creo que le falta un modulo o herramienta que me permita subir archivos a mi server facil y sencillo" |
| Riesgo | HIGH (subida de archivos = superficie de ataque) |
| Enlaces | [spec.md](spec.md) · [design.md](design.md) · [plan.md](plan.md) · [bitacora.md](bitacora.md) |

## Qué es

Módulo del core (estilo `core/Realtime`) que permite subir archivos a un servidor
Apollo de forma simple: accessors en `Request`, un manager de almacenamiento
(`UploadManager` + disco local), reglas de validación de archivos y `Response::download`.
Se integra en cualquier controlador. La app REST `apps/Uploads` se construyó como
ejemplo y se **retiró** por decisión del usuario (el core basta); la receta de
endpoints quedó en `docs/uploads.md`.