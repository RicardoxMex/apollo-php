# Spec — Módulo Uploads (subida de archivos)

> Orden del usuario: "creo que le falta un modulo o herramienta que me permita subir archivos a mi server facil y sencillo".

## Contexto

Apollo no tiene soporte de subida de archivos: `Request` captura `$_FILES` pero en una
propiedad privada sin accessors; `RuleRegistry` no tiene reglas de archivos (`file`,
`mimes`, tamaño en KB); `Response` no tiene helpers binarios (`download`); no existe
configuración de almacenamiento. Patrón de referencia del repo: módulo opcional
`core/Realtime/` + app opcional `apps/Realtime/` (provider + config + helper + docs).

## Requisitos (criterios de aceptación)

1. **Accessors en `Request`**: `file('foto')` → `UploadedFile|null`, `hasFile('foto')`,
   `files('galeria')` → array de `UploadedFile` (multi-file de `$_FILES` normalizado),
   `allFiles()` → todos. No rompen API existente.
2. **Módulo core `core/Uploads/`** (`Apollo\Core\Uploads`): `UploadedFile` (VO sobre la
   entrada de `$_FILES`: `originalName`, `extension`, `size`, `mime`, `error`, `isValid`,
   `store`/`storeAs`, `moveTo`), `Contracts\Disk` + `Support\LocalDisk` (root
   `storage/uploads`, gitignored), `Support\UploadConfig` (tipado, defaults), y
   `Support\UploadManager`: nombres únicos + saneado de nombre, enforcement de
   `max_size` y `allowed_mimes` de config, `store/get/delete/exists/url/path`.
3. **Reglas de validación**: `file`, `mimes:jpg,png,...`, `image`, y semántica de
   archivo (tamaño en KB) para `max`/`min`/`between`/`size`. Aceptan arrays de `$_FILES`
   y objetos `UploadedFile`.
4. **`Response::download($path, $name)`**: sirve binario con `Content-Disposition`
   (attachment), MIME por `finfo` con fallback `application/octet-stream`, resolución
   segura de path (sin path traversal).
5. **App REST opcional `apps/Uploads`** (registrada por defecto): `POST /api/uploads`
   (campo `file`, single), `POST /api/uploads/multiple` (campo `files`), `GET
   /api/uploads/{path:.+}` (serve inline, `?download=1` → attachment), `DELETE
   /api/uploads/{path:.+}`. Writes y reads tras middleware `auth`; whitelist `mimes`
   por endpoint + `max` en KB; envelopes JSON del framework (`success`/`data`/`error`).
6. **`composer test` pasa**: nuevos tests unit (UploadedFile, LocalDisk, UploadManager,
   Request files, reglas de archivos, download) y feature (app Uploads vía dispatch sin
   servidor web ni DB).
7. **Docs y entorno**: `docs/uploads.md` (español, convención de `docs/realtime.md`),
   enlace en `docs/README.md`, claves `UPLOADS_*` en `.env.example`, `storage/` en
   `.gitignore`.

## Fuera de alcance

- Discos cloud (S3/GCS): queda la interfaz `Disk` lista, solo implementa `LocalDisk`.
- Metadatos en DB (tabla de uploads): v1 es filesystem-only (decisión D1).
- Chunked/resumable uploads, thumbnails/resizing, CLI commands, frontend/SDK.
- Migración de DB: ninguna (no hay tabla nueva).

## Criterios de seguridad (G5)

- Sin path traversal: resolución de paths dentro del root (`realpath` containment).
- Nombres saneados: solo `[A-Za-z0-9._-]`, extensión conservada, nombre único generado.
- `mimes` por extensión + sniff de MIME vía `finfo` (cuando esté disponible) en manager.
- Tamaño máximo configurable (default 10 MB) aplicado en manager (no solo en regla).
- La app expone los archivos solo con `auth`; el disco es privado (fuera de `public/`).