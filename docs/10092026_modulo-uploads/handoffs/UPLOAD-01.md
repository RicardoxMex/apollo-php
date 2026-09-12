# Handoff — UPLOAD-01

## Output Contract

| Campo | Contenido |
|---|---|
| Status | COMPLETED |
| Summary | Módulo core `core/Uploads/` completo: `UploadedFile` (VO sobre `$_FILES`), `Contracts\Disk` + `LocalDisk` (disco privado con anti-traversal), `UploadConfig` tipado, `UploadManager` (nombres únicos, límites server-side), `UploadsServiceProvider` (alias `uploads`), `config/uploads.php`, helper global `uploads()`. 35 tests unit verdes. |
| Files Changed | ver abajo |
| Decisions | D1–D8 de `design.md` (sin DB, VO, disco local, disco privado, nombres únicos, app registrada, doble validación, extensión+sniff) |
| Problems | Ninguno pendiente |
| Risks | Sin `extension=fileinfo` el sniff de MIME se degrada al `type` del cliente (documentado en docs/uploads.md) |
| Validation | `phpunit tests/Unit/Uploads` (35 OK) · suite completa 148 OK |
| Next Steps | UPLOAD-02/03/04 consumen el contrato |

## Files Changed

- `core/Uploads/Contracts/Disk.php` — contrato de disco
- `core/Uploads/Support/UploadedFile.php` — VO de subida
- `core/Uploads/Support/LocalDisk.php` — disco local seguro
- `core/Uploads/Support/UploadConfig.php` — config tipada
- `core/Uploads/Support/UploadManager.php` — servicio central
- `core/Uploads/Exceptions/UploadException.php` — excepción de negocio
- `core/Providers/UploadsServiceProvider.php` — bindings + alias
- `config/uploads.php` — configuración del módulo
- `config/providers.php` — provider registrado
- `core/Support/helpers.php` — helper `uploads()`
- `tests/Unit/Uploads/*` — 4 archivos de test