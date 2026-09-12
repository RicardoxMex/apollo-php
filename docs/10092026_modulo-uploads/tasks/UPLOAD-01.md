# Task — UPLOAD-01

> Tarjeta humana. Registro autoritativo: `.ai/state/store/tasks.json`.

| Campo | Valor |
|---|---|
| ID | UPLOAD-01 |
| Milestone | M1 — Core del módulo |
| Riesgo | MEDIUM |
| Estado | COMPLETED (revisado y aprobado) |

## Descripción

Crear el módulo core `core/Uploads/` (namespace `Apollo\Core\Uploads`) siguiendo el
patrón de `core/Realtime/`:

- `Contracts/Disk.php` — contrato: `put/get/delete/exists/path/url/size/mime`.
- `Support/UploadedFile.php` — VO sobre entrada de `$_FILES` (`name/tmp_name/type/size/error`):
  `originalName()`, `extension()`, `size()`, `mime()`, `error()`, `isValid()`,
  `store($dir, $options)`, `storeAs($dir, $name)`, `moveTo($path)`, `hashName()`.
- `Support/LocalDisk.php` — almacenamiento en `storage/uploads` (root desde config).
  `put` con creación de directorios, nombre único opcional, paths normalizados (sin `..`).
- `Support/UploadConfig.php` — accessor tipado: `driver/root/urlPrefix/maxSizeKb/allowedMimes/overwrite`.
- `Support/UploadManager.php` — servicio central: `store(UploadedFile|array, $directory, $options)`
  (devuelve array `{path,url,name,size,mime}`), `get($path)`, `delete($path)`, `exists($path)`,
  `url($path)`, `disk()`, `config()`. Enforcement server-side: `max_size` KB y `allowed_mimes`.
  Nombres únicos: `{ts}_{random}.{ext}` saneada. Rechaza `..`, slashes y nombres vacíos.
- `core/Providers/UploadsServiceProvider.php` — `singleton(UploadManager::class)` con
  `config('uploads', [])` + alias `uploads`. Registrar en `config/providers.php` (core).
- `config/uploads.php` — `driver=local`, `root=storage/uploads`, `url_prefix=/uploads`,
  `max_size` (env `UPLOADS_MAX_SIZE`, default 10240 KB), `allowed_mimes` (env
  `UPLOADS_ALLOWED_MIMES`, default null), `overwrite=false`.
- Helper global `uploads()` en `core/Support/helpers.php`.

Tests unit en `tests/Unit/Uploads/` (sin DB): UploadedFileTest, LocalDiskTest,
UploadManagerTest, UploadConfigTest. Usar directorios temporales (`sys_get_temp_dir`).

## Inputs

- `docs/10092026_modulo-uploads/spec.md`, `design.md` (D1, D3, D5, D7, D8)
- Referencia: `core/Providers/RealtimeServiceProvider.php`, `config/realtime.php`

## File Ownership

Solo los archivos listados en `file_ownership` (tasks.json). No tocar `Request`, `Response`,
`RuleRegistry` ni `apps/`.

## Validation

Criterios en tasks.json (`UPLOAD-01.validation_criteria`). Ejecutar:
`C:\tools\php84\php.exe vendor/bin/phpunit tests/Unit/Uploads`