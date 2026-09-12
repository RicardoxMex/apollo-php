# Task — UPLOAD-03

| Campo | Valor |
|---|---|
| ID | UPLOAD-03 |
| Milestone | M1 — Core del módulo |
| Riesgo | MEDIUM |
| Estado | COMPLETED (revisado y aprobado) |

## Descripción

En `core/Validation/RuleRegistry.php` añadir reglas de archivos (mantener mensajes en
español, convención `:field`/`:param`):

- `file` — valor es array de `$_FILES` con `error === UPLOAD_ERR_OK` y `tmp_name` legible,
  o instancia de `UploadedFile` válida.
- `mimes:jpg,png,...` — extensión del nombre original (minúsculas, sin dot) en la lista.
- `image` — MIME del archivo empieza con `image/` (usa `UploadedFile::mime()`; fallback
  `$_FILES.type`).
- Semántica de tamaño en KB para `min`/`max`/`between`/`size`: extender el `$span` para
  que, cuando el valor es un array con `size` (o `UploadedFile`), devuelva `size / 1024`.

No cambiar el comportamiento para strings/arrays/numéricos (tests existentes de
`ValidationTest` deben seguir pasando).

## Inputs

- `docs/10092026_modulo-uploads/spec.md` (AC3)
- `core/Validation/RuleRegistry.php`, `core/Uploads/Support/UploadedFile.php`

## File Ownership

`core/Validation/RuleRegistry.php`, `tests/Unit/FileValidationRulesTest.php`

## Validation

Criterios en tasks.json. Ejecutar:
`C:\tools\php84\php.exe vendor/bin/phpunit tests/Unit/FileValidationRulesTest.php tests/Unit/ValidationTest.php`