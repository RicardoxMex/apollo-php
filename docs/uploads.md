# Módulo Uploads — Subida de archivos

Módulo del core (estilo `core/Realtime/`) que permite subir archivos a tu servidor
Apollo de forma fácil y sencilla: accessors en `Request`, un manager de almacenamiento
con disco local, reglas de validación y descarga vía `Response`. **No hay app REST
asociada**: cada controlador integra el upload donde lo necesite (crear un producto,
avatar de usuario, adjuntos...).

## Características

- `$request->file('foto')` / `hasFile()` / `files()` / `allFiles()` — acceso a los archivos subidos.
- `core/Uploads/` — `UploadedFile` (VO), `UploadManager` (almacenamiento), `LocalDisk` (disco local).
- Reglas de validación: `file`, `mimes:jpg,png,...`, `image`, y `max`/`min`/`between`/`size` en **KB** para archivos.
- `Response::download()` / `Response::file()` — servir archivos (attachment o inline).
- Nombres únicos y saneados, defensa contra path traversal, límites server-side.

## Configuración (config/uploads.php, env)

| Clave | Default | Env | Descripción |
|---|---|---|---|
| `driver` | `local` | `UPLOADS_DRIVER` | Disco activo (v1: solo `local`) |
| `root` | `storage/uploads` | `UPLOADS_PATH` | Directorio de almacenamiento (relativo al proyecto o absoluto) |
| `url_prefix` | `/uploads` | — | Prefijo de las URLs públicas generadas |
| `max_size` | `10240` | `UPLOADS_MAX_SIZE` | Tamaño máximo en KB (10 MB) |
| `allowed_mimes` | `null` | `UPLOADS_ALLOWED_MIMES` | Extensiones permitidas (CSV); vacío = sin restricción |
| `overwrite` | `false` | — | Si `false`, colisiones se renombran con sufijo |

> El root vive fuera de `public/` y está gitignored (`storage/uploads/*`).

## Uso básico (backend PHP)

```php
// En un controlador
public function uploadFoto(Request $request): Response
{
    $file = $request->file('foto');          // ?UploadedFile

    if (!$request->hasFile('foto')) {
        return Response::json(['error' => 'Validation Error', 'message' => 'foto requerida'], 422);
    }

    // Validar (reglas de archivos del core)
    $this->validate(['foto' => $file], [
        'foto' => 'required|file|mimes:jpg,png,webp|max:2048',  // 2 MB
    ]);

    // Almacenar (nombre único generado). También $file->store('carpeta')
    $resultado = uploads()->store($file, 'fotos/2026');

    return Response::json(['success' => true, 'data' => $resultado], 201);
    // data: { name, path, url, size, mime }
}
```

El manager también acepta el array crudo de `$_FILES`:

```php
uploads()->store($_FILES['archivo'], 'docs');
uploads()->storeAs($_FILES['archivo'], 'docs', 'informe.pdf');
```

Otros métodos del manager: `get($path)` (contenido), `exists($path)`, `delete($path)`,
`url($path)`, `path($path)` (absoluto validado). Helper global: `uploads()`.

## Endpoints REST listos para copiar (receta)

Si quieres endpoints de subida genéricos, copia esta receta en **tu** app (el módulo
core no registra ninguna app): rutas + controlador + protección con `auth`.

```php
// apps/<TuApp>/Routes/api.php
use Apps\<TuApp>\Controllers\UploadController;

/** @var \Apollo\Core\Router\Router $router */

$router->group(['middleware' => ['auth']], function ($router) {
    $router->post('/', [UploadController::class, 'store'])->name('uploads.store');
    $router->post('/multiple', [UploadController::class, 'storeMultiple'])->name('uploads.storeMultiple');
    $router->get('/{path:.+}', [UploadController::class, 'show'])->name('uploads.show');
    $router->delete('/{path:.+}', [UploadController::class, 'destroy'])->name('uploads.destroy');
});
```

```php
// apps/<TuApp>/Controllers/UploadController.php
class UploadController extends \Apollo\Core\Http\Controller
{
    private const ALLOWED_MIMES = 'jpg,jpeg,png,gif,webp,pdf,zip';

    public function __construct(
        \Apollo\Core\Container\Container $container,
        \Apollo\Core\Uploads\Support\UploadManager $uploads,
    ) {
        parent::__construct($container);
        $this->uploads = $uploads;
    }

    public function store(Request $request): Response
    {
        try {
            $file = $request->file('file');

            $this->validate(['file' => $file], [
                'file' => 'required|file|mimes:' . self::ALLOWED_MIMES . '|max:' . $this->uploads->config()->maxSizeKb(),
            ]);

            return Response::json(['success' => true, 'data' => $this->uploads->store($file)], 201);
        } catch (ValidationException $e) {
            return Response::json(['error' => 'Validation Error', 'errors' => $e->errors()], 422);
        } catch (UploadException $e) {
            return Response::json(['error' => 'Upload Error', 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return Response::json(['error' => 'Internal Server Error'], 500);
        }
    }

    // storeMultiple (campo `files`), show (Response::file/download), destroy: ver
    // "Subir imagen al crear un recurso" y "Descarga de archivos" para el patrón.
}
```

Con la app montada en `api/uploads` (campo `prefix` de su `app.json`):

```bash
# Subir un archivo
curl -X POST http://localhost:8000/api/uploads \
  -H "Authorization: Bearer <token>" \
  -F "file=@foto.png"

# Respuesta (201)
# { "success": true, "data": { "name": "a1b2c3....png", "path": "fotos/a1b2....png",
#                              "url": "/uploads/fotos/a1b2....png", "size": 1234, "mime": "image/png" } }

# Servir / descargar / borrar
curl http://localhost:8000/api/uploads/fotos/a1b2....png \
  -H "Authorization: Bearer <token>"
curl "http://localhost:8000/api/uploads/fotos/a1b2....png?download=1" \
  -H "Authorization: Bearer <token>"
curl -X DELETE http://localhost:8000/api/uploads/fotos/a1b2....png \
  -H "Authorization: Bearer <token>"

# Múltiple
curl -X POST http://localhost:8000/api/uploads/multiple \
  -H "Authorization: Bearer <token>" \
  -F "files[]=@a.png" -F "files[]=@b.pdf"
```

## Rutas y almacenamiento personalizados

El módulo core no te obliga a ningún esquema de rutas ni de carpetas. Cada dev puede
montar el patrón que prefiera (por modelo, por usuario, por id...).

### Almacenar usando el nombre del modelo como directorio

El directorio que pases a `store()` se refleja en la URL (`{path:.+}` acepta slashes):

```php
// storage/uploads/users/20wi-21i01i-0/a1b2c3....png
$resultado = uploads()->store($file, 'users/' . $id);

// → GET /api/uploads/users/20wi-21i01i-0/a1b2c3....png  (sirve el archivo)
// → DELETE /api/uploads/users/20wi-21i01i-0/a1b2c3....png
```

### Almacenar con un nombre exacto (el id del modelo como nombre)

Con `storeAs()` el nombre almacenado es el que tú decides (incluye la extensión):

```php
uploads()->storeAs($file, 'users', $id . '.png');
// → storage/uploads/users/20wi-21i01i-0.png
// → GET /api/uploads/users/20wi-21i01i-0.png
```

> Si el nombre ya existe y `overwrite=false` (default), se renombra con sufijo
> (`20wi-21i01i-0_1.png`). Si quieres que el id siempre gane, activa
> `'overwrite' => true` en `config/uploads.php`.

### Crear tus propias rutas (convención de cada dev)

El core (`Request::file()`, `uploads()`, reglas `file|mimes|max`) se usa desde
cualquier controlador; define las rutas en la `Routes/api.php` de TU app:

```php
// apps/Users/Routes/api.php
$router->post('/users/{id}/avatar', [UserController::class, 'uploadAvatar'])
    ->where(['id' => '[a-z0-9-]+']);          // o inline: /users/{id:[a-z0-9-]+}/avatar
$router->get('/users/{id}/avatar', [UserController::class, 'getAvatar']);
```

```php
// apps/Users/Controllers/UserController.php
public function uploadAvatar(Request $request, string $id): Response
{
    $file = $request->file('avatar');

    $this->validate(['avatar' => $file], [
        'avatar' => 'required|file|mimes:jpg,png,webp|max:2048',
    ]);

    $resultado = uploads()->store($file, 'users/' . $id);

    return $this->json(['success' => true, 'data' => $resultado], 201);
}

public function getAvatar(Request $request, string $id): Response
{
    // Buscar el archivo por convención del modelo: users/{id}/<nombre>.png
    $path = 'users/' . $id . '/' . $request->query('name', 'avatar.png');

    $absoluto = uploads()->path($path);

    if ($absoluto === null || !uploads()->exists($path)) {
        return $this->json(['error' => 'Not Found'], 404);
    }

    return Response::file($absoluto);
}
```

Resultado: `POST /api/users/20wi-21i01i-0/avatar` y
`GET /api/users/20wi-21i01i-0/avatar?name=avatar.png`.

## Subir imagen al crear un recurso (ejemplo: Producto)

El upload va **dentro del mismo request** de creación: multipart con los campos del
recurso + el archivo. Los campos normales llegan a `$_POST` (→ `$request->input()`),
el archivo a `$_FILES` (→ `$request->file()`). El patrón es idéntico en cualquier
controlador (Products, Users, el que sea).

```php
// apps/Products/Controllers/ProductController.php
use Apollo\Core\Uploads\Exceptions\UploadException;
use Apollo\Core\Validation\ValidationException;

public function store(Request $request): Response
{
    try {
        $imagen = $request->file('image');

        // Validar campos e imagen en un solo validate()
        $this->validate([
            'name'  => $request->input('name'),
            'price' => $request->input('price'),
            'image' => $imagen,
        ], [
            'name'  => 'required|string|min:3',
            'price' => 'required|numeric',
            'image' => 'required|file|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        // Subir al servidor → path (guardar en BD) + url (devolver al cliente)
        $resultado = uploads()->store($imagen, 'products');
        // = ['name' => 'a1b2c3....jpg', 'path' => 'products/a1b2c3....jpg',
        //    'url'  => '/uploads/products/a1b2c3....jpg', 'size' => 12345, 'mime' => 'image/jpeg']

        $producto = Product::create([
            'name'  => $request->input('name'),
            'price' => $request->input('price'),
            'image' => $resultado['path'],
        ]);

        return $this->json([
            'success' => true,
            'data' => [
                'id'        => $producto->id,
                'name'      => $producto->name,
                'image_url' => $resultado['url'],
            ],
        ], 201);

    } catch (ValidationException $e) {
        return $this->json(['error' => 'Validation Error', 'errors' => $e->errors()], 422);
    } catch (UploadException $e) {
        return $this->json(['error' => 'Upload Error', 'message' => $e->getMessage()], 422);
    } catch (\Throwable $e) {
        return $this->json(['error' => 'Failed to create product'], 500);
    }
}
```

El cliente llama al mismo endpoint con `multipart/form-data`:

```bash
curl -X POST http://localhost:8000/api/products \
  -H "Authorization: Bearer <token>" \
  -F "name=Camiseta" \
  -F "price=19.99" \
  -F "image=@camiseta.jpg"
```

Ciclo completo del archivo:

```php
// Servir la imagen (show() o ruta dedicada)
$absoluto = uploads()->path($producto->image);   // valida y resuelve
return Response::file($absoluto);                 // o Response::download()

// Actualizar: si viene imagen nueva, borrar la vieja
$imagen = $request->file('image');
$this->validate(['image' => $imagen], ['image' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:2048']);

if ($imagen && $producto->image) {
    uploads()->delete($producto->image);
}
if ($imagen) {
    $producto->image = uploads()->store($imagen, 'products')['path'];
}

// Borrar: limpiar también el archivo
if ($producto->image) {
    uploads()->delete($producto->image);
}
```

> Nota: `$request->all()` mezcla query + POST pero **no incluye archivos**. Usa
> `$request->file('image')` para el archivo y `$request->input()` para el resto.

## Validación

Reglas nuevas del core (`core/Validation/RuleRegistry`):

| Regla | Descripción |
|---|---|
| `file` | Es una subida válida (error `UPLOAD_ERR_OK` y archivo presente) |
| `mimes:jpg,png` | La extensión del nombre está en la lista |
| `image` | El MIME es `image/*` |
| `max:2048` / `min` / `between` / `size` | Tamaño en **KB** cuando el valor es un archivo |

```php
validator(['archivo' => $request->file('archivo')], [
    'archivo' => 'file|mimes:pdf|max:5120',
]);
```

## Descarga de archivos

```php
use Apollo\Core\Http\Response;

// Adjunto (descarga)
return Response::download($pathAbsoluto, 'informe.pdf');

// Inline (abre en el navegador)
return Response::file($pathAbsoluto);
```

`download()` y `file()` devuelven 404 JSON si el archivo no existe. MIME detectado con
`finfo` cuando está disponible (fallback `application/octet-stream`).

## Seguridad

- **Path traversal**: `LocalDisk` normaliza por segmentos y rechaza `..`; las lecturas se
  contienen en el root vía `realpath`. Los endpoints `{path}` pasan por el manager.
- **Mimes y tamaño**: validados dos veces — en el controlador (422 legible) y en el
  `UploadManager` (defensa server-side, aunque el llamador no valide).
- **Auth**: protege con el middleware `auth` (JWT) las rutas que sirven archivos; el
  disco es privado (fuera de `public/`).
- **Nombres**: generados por el servidor (único + saneado); el nombre del cliente solo
  aporta la extensión (validada contra la whitelist).
- **Sniff de MIME**: con `extension=fileinfo` el manager verifica que el contenido real
  concuerde con la extensión permitida. Sin `fileinfo`, confía en el `type` declarado por
  el cliente — habilita `extension=fileinfo` en producción.

## Integración en tu proyecto

El módulo ya está activo en cualquier proyecto Apollo (provider registrado en
`config/providers.php` + `config/uploads.php` auto-cargado): no hay que registrar nada.

1. Usa `$request->file()` en tu controlador y valida con `file|mimes|max`.
2. Guarda con `uploads()->store()` el `path` que devuelve (en tu modelo/BD).
3. Sirve con `uploads()->path()` + `Response::file()` en la ruta que definas.
4. Borra con `uploads()->delete()` cuando el recurso se elimine.

Si no usas el módulo, quita `UploadsServiceProvider` de `config/providers.php`.

## Limitaciones documentadas

- Solo disco local en v1 (la interfaz `Disk` está lista para drivers S3/GCS).
- Sin metadatos en DB: los archivos viven solo en el filesystem (decisión D1 del plan).
- Sin chunked/resumable uploads, thumbnails ni redimensionado de imágenes.
- `GET` y `DELETE` por path requieren el path exacto devuelto por el upload (no hay índice).
- Sin `extension=fileinfo` el control de MIME real se degrada al `type` declarado por el cliente.
- Los límites de PHP (`upload_max_filesize`, `post_max_size` en php.ini) se aplican antes
  que el módulo: si subes archivos grandes, súbelos por encima de `config('uploads.max_size')`.
- Sin rate limiting en los endpoints de subida (gap del framework, no del módulo).