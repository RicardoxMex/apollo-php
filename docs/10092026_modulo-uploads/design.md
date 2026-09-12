# Design — Módulo Uploads

Decisiones de arquitectura (registradas; las significativas referencian `D<NN>`).

## D1 — Sin tabla de metadatos en DB (v1)

Los archivos viven solo en disco (`storage/uploads`); la URL/path se deriva del path
almacenado que devuelve el manager. Evita migración, modelo y repositorio para el caso
común; la interfaz `Disk` permite añadir metadatos después sin romper la API.

## D2 — `UploadedFile` como VO sobre la entrada de `$_FILES`

`Request::file()` devuelve `UploadedFile|null` (envuelve `name/tmp_name/type/size/error`).
El VO centraliza `isValid()`, `extension()` y `store()`; las reglas de validación y el
manager aceptan tanto el VO como el array crudo (BC para quien valide arrays).

## D3 — Interfaz `Disk` + implementación `LocalDisk`

Contrato: `put/get/delete/exists/path/url/size/mime`. Solo `LocalDisk` en v1; futuros
drivers (S3) implementan el contrato. El manager resuelve el driver por config
(`uploads.driver`, default `local`).

## D4 — Disco privado + serve por ruta (no estático)

`storage/uploads` queda fuera de `public/` y gitignored. Los archivos se sirven vía
`GET /api/uploads/{path}` (con `auth`), no por el servidor web estático → control de
acceso y `Content-Disposition`. Defensa anti-traversal: `realpath` del root + prefijo.

## D5 — Nombres únicos y saneados

El manager genera el nombre almacenado: `{directorio}/{timestamp}_{bin2hex(random)}.{ext}`
(extensión del original, saneada). El nombre original se conserva solo como metadato en
la respuesta. Sin `..`, sin slashes, sin caracteres de control.

## D6 — App `apps/Uploads` registrada por defecto

> **REVOCADA (2026-09-10, decisión del usuario):** la app REST se retiró del repo.
> El módulo core basta — cada controlador integra el upload donde lo necesite.
> La receta de endpoints (rutas + controlador con `auth`) vive en `docs/uploads.md`
> como plantilla copiable. D1-D5, D7, D8 siguen vigentes.

## D7 — Validación en dos capas

1. Capa de reglas (request): `file|mimes|max` para errores 422 legibles.
2. Capa de manager (defensa): `UploadManager` re-valida `allowed_mimes` + `max_size`
   de config aunque el llamador no valide (server-side enforcement, G5).

## D8 — MIME: extensión + sniff

`mimes` compara contra la extensión del nombre original (normalizada a minúsculas);
`image` usa el MIME de `$_FILES['type']` o `finfo` si está disponible. El manager
rechaza cuando el sniff (`finfo_file`) no coincide con la whitelist configurada.