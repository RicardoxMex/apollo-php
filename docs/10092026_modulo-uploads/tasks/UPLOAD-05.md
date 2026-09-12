# Task — UPLOAD-05

| Campo | Valor |
|---|---|
| ID | UPLOAD-05 |
| Milestone | M2 — App REST + integración |
| Riesgo | HIGH (seguridad: subida/servicio de archivos) |
| Estado | COMPLETED (revisado y aprobado) |

## Descripción

Crear `apps/Uploads/` (patrón `apps/Realtime/`):

- `app.json` — name `Uploads`, prefix `api/uploads`, routes `["api.php"]`, providers `[]`.
- `Routes/api.php` — grupo con middleware `['auth']`:
  - `POST /` → `UploadController::store` (campo `file`)
  - `POST /multiple` → `UploadController::storeMultiple` (campo `files`)
  - `GET /{path:.+}` → `UploadController::show` (`?download=1` → attachment)
  - `DELETE /{path:.+}` → `UploadController::destroy`
- `Controllers/UploadController.php` — extiende `Apollo\Core\Http\Controller`, usa
  `$this->validate()` con reglas `required|file|mimes:jpg,jpeg,png,gif,webp,pdf,zip|max:{maxKb}`
  y el `UploadManager` del container. Respuestas envelope del framework
  (`success`/`data` o `error` con `ValidationException` → 422, archivo no encontrado → 404).
  Path de GET/DELETE: pasar a `uploads()->get($path)` — el manager defiende el traversal.
- Registrar `Uploads` en `config/apps.php` `registered`.
- `.env.example`: `UPLOADS_MAX_SIZE=10240`, `UPLOADS_ALLOWED_MIMES=`.
- `storage/uploads/.gitignore` (`*` + `!.gitignore`) y `storage/` en `.gitignore` raíz.
- Feature tests `tests/Feature/Uploads/UploadsApiTest.php` — dispatch sin DB: upload 200,
  422 sin archivo, 422 mime inválido, 401 sin token, GET sirve archivo, DELETE borra.
  Para subir archivos, construir `Request` con `$files` y despachar vía `$app->handle()`.

## Inputs

- `docs/10092026_modulo-uploads/spec.md` (AC5), `design.md` (D4, D6, D7)
- Referencia: `apps/Realtime/`, `tests/Feature/ApiSmokeTest.php`, `tests/TestCase.php`

## File Ownership

`apps/Uploads/`, `config/apps.php`, `.env.example`, `storage/`, `tests/Feature/Uploads/`

## Validation

Criterios en tasks.json. Ejecutar: `C:\tools\php84\php.exe vendor/bin/phpunit tests/Feature/Uploads`