# Plan — Módulo Uploads

## L1 — Objetivo

Dar a Apollo un módulo + herramienta para subir archivos al servidor de forma fácil y
sencilla: accessors en `Request`, manager de almacenamiento en disco, reglas de
validación, descarga vía `Response`, y una app REST lista (`POST/GET/DELETE`).

## L2 — Milestones

| ID | Título | Riesgo | Estado |
|---|---|---|---|
| M1 | Core del módulo (manager, VO, disco, request, validación, response) | MEDIUM | not-started |
| M2 | App REST + integración + docs | HIGH | not-started |

## L3 — Tareas

| ID | Tarea | Deps | Riesgo | Prioridad |
|---|---|---|---|---|
| UPLOAD-01 | Módulo core `core/Uploads/` (UploadedFile, Disk, LocalDisk, UploadConfig, UploadManager) + provider + `config/uploads.php` + helper `uploads()` + tests | — | MEDIUM | P0 |
| UPLOAD-02 | Accessors de archivos en `Request` (`file/hasFile/files/allFiles`) + tests | UPLOAD-01 | MEDIUM | P0 |
| UPLOAD-03 | Reglas de validación `file`/`mimes`/`image` + tamaño en KB (max/min/between/size) + tests | UPLOAD-01 | MEDIUM | P0 |
| UPLOAD-04 | `Response::download()` (binario, Content-Disposition, path seguro) + tests | — | LOW | P1 |
| UPLOAD-05 | App REST `apps/Uploads/` + registro en `config/apps.php` + `.env.example` + `storage/` + feature tests | UPLOAD-01, UPLOAD-02, UPLOAD-03, UPLOAD-04 | HIGH | P0 |
| UPLOAD-06 | Docs `docs/uploads.md` + índice `docs/README.md` | UPLOAD-05 | LOW | P2 |

## Grafo de dependencias

```text
UPLOAD-01 ──► UPLOAD-02 ──┐
UPLOAD-01 ──► UPLOAD-03 ──┼──► UPLOAD-05 ──► UPLOAD-06
UPLOAD-04 ────────────────┘
```

## Estrategias por cluster

- M1: `sequential` (implementador único; los archivos son disjuntos pero el coste de
  fan-out no justifica agentes extra — regla del OS: paralelizar solo cuando ahorra).
  Fan-in owner: backend.
- M2: `sequential` (la app consume el contrato de M1). Fan-in owner: backend.