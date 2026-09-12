# Task — UPLOAD-04

| Campo | Valor |
|---|---|
| ID | UPLOAD-04 |
| Milestone | M1 — Core del módulo |
| Riesgo | LOW |
| Estado | COMPLETED (revisado y aprobado) |

## Descripción

En `core/Http/Response.php` añadir:

- `static download(string $path, ?string $name = null, array $headers = []): self` —
  lee el archivo, `Content-Type` vía `finfo_file` (fallback `application/octet-stream`),
  `Content-Disposition: attachment; filename="..."`, `Content-Length`. Si el archivo no
  existe → `Response::json(['error' => 'Not Found', ...], 404)`.
- `static file(string $path, array $headers = []): self` — sirve inline (sin attachment).

## Inputs

- `docs/10092026_modulo-uploads/spec.md` (AC4)
- `core/Http/Response.php`

## File Ownership

`core/Http/Response.php`, `tests/Unit/DownloadResponseTest.php`

## Validation

Criterios en tasks.json. Ejecutar: `C:\tools\php84\php.exe vendor/bin/phpunit tests/Unit/DownloadResponseTest.php`