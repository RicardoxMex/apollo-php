# Handoff — UPLOAD-04

## Output Contract

| Campo | Contenido |
|---|---|
| Status | COMPLETED |
| Summary | `Response::download()` (attachment) y `Response::file()` (inline) con MIME vía finfo (fallback octet-stream), `Content-Length`, 404 JSON si falta el archivo y nombres saneados contra header injection. 6 tests verdes. |
| Files Changed | `core/Http/Response.php`, `tests/Unit/DownloadResponseTest.php` |
| Decisions | Helper genérico que toma path absoluto (responsabilidad del llamador) |
| Problems | Ninguno |
| Risks | Ninguno |
| Validation | `phpunit tests/Unit/DownloadResponseTest.php` (6 OK) |
| Next Steps | Consumido por UPLOAD-05 (show con `?download=1`) |