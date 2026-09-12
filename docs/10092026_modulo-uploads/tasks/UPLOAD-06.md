# Task — UPLOAD-06

| Campo | Valor |
|---|---|
| ID | UPLOAD-06 |
| Milestone | M2 — App REST + integración |
| Riesgo | LOW |
| Estado | COMPLETED (revisado y aprobado) |

## Descripción

Escribir `docs/uploads.md` en español siguiendo la estructura de `docs/realtime.md`:

1. Introducción + características + estado opcional.
2. `## Configuración (config/uploads.php, env)` — claves y defaults.
3. `## Uso básico` — PHP backend (`$request->file()`, `uploads()->store()`, validación).
4. `## Endpoints REST (apps/Uploads)` — tabla de rutas + ejemplos curl.
5. `## Validación` — reglas `file|mimes|image|max`.
6. `## Descarga de archivos` — `Response::download()`.
7. `## Seguridad` — traversal, mimes, tamaño, auth.
8. `## Activación / desactivación` — registrar/quitar de `config/apps.php`.

Añadir enlace en `docs/README.md` (sección Guías).

## Inputs

- `docs/10092026_modulo-uploads/spec.md`, `docs/realtime.md`

## File Ownership

`docs/uploads.md`, `docs/README.md`

## Validation

Criterios en tasks.json.