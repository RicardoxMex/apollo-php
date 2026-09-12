# Task — UPLOAD-02

| Campo | Valor |
|---|---|
| ID | UPLOAD-02 |
| Milestone | M1 — Core del módulo |
| Riesgo | MEDIUM |
| Estado | COMPLETED (revisado y aprobado) |

## Descripción

Añadir a `core/Http/Request.php` los accessors de archivos:

- `file(string $key): ?UploadedFile` — normaliza `$_FILES` y envuelve en `UploadedFile`.
- `hasFile(string $key): bool` — existe y es un archivo subido válido (error == UPLOAD_ERR_OK).
- `files(?string $key = null): array` — array de `UploadedFile` (multi: `files[0]`, `files[n]`, `files[]`).
- `allFiles(): array` — todos los archivos como array de `UploadedFile`.

Normalización: si el valor es un array con keys `tmp_name` → single; si es lista de
arrays con `tmp_name` → multi. Campos vacíos (`UPLOAD_ERR_NO_FILE`) → ignorados/nulo.

No romper API existente.

## Inputs

- `docs/10092026_modulo-uploads/spec.md` (AC1)
- `core/Http/Request.php`, `core/Uploads/Support/UploadedFile.php`

## File Ownership

`core/Http/Request.php`, `tests/Unit/RequestFileTest.php`

## Validation

Criterios en tasks.json. Ejecutar: `C:\tools\php84\php.exe vendor/bin/phpunit tests/Unit/RequestFileTest.php`