# Handoff — UPLOAD-02

## Output Contract

| Campo | Contenido |
|---|---|
| Status | COMPLETED |
| Summary | `Request` expone `file()/hasFile()/files()/allFiles()` devolviendo `UploadedFile`, con normalización de estructuras `$_FILES` multi-file (`files[]`, `files[n]`). API existente intacta. 10 tests verdes. |
| Files Changed | `core/Http/Request.php`, `tests/Unit/RequestFileTest.php` |
| Decisions | Estructura exótica `files[n][name]` fuera de alcance (no la genera un input estándar) |
| Problems | Ninguno |
| Risks | Ninguno |
| Validation | `phpunit tests/Unit/RequestFileTest.php` (10 OK) |
| Next Steps | Consumido por UPLOAD-05 (feature tests de la app) |

## Important Context

- `file()` con multi de un solo elemento devuelve el primero; `files($key)` devuelve lista.
- Archivos con `error != UPLOAD_ERR_OK` se envuelven igual; `hasFile()` filtra por validez.