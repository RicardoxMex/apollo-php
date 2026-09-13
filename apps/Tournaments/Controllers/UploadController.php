<?php

namespace Apps\Tournaments\Controllers;

use Apollo\Core\Container\Container;
use Apollo\Core\Http\Controller;
use Apollo\Core\Http\Response;
use Apollo\Core\Uploads\Exceptions\UploadException;
use Apollo\Core\Uploads\Support\UploadManager;

/**
 * Subida y servicio de archivos con el módulo nativo de Uploads (core/Uploads).
 *
 * - POST /api/uploads (auth): guarda el archivo con UploadManager y devuelve
 *   la URL pública ({ name, path, url, size, mime }).
 * - GET /api/uploads/{path}: sirve el archivo (LocalDisk valida el path,
 *   sin traversal).
 */
class UploadController extends Controller
{
    public function __construct(Container $container, private UploadManager $uploads)
    {
        parent::__construct($container);
    }

    public function store()
    {
        try {
            $file = $this->request->file('file');

            if ($file === null) {
                return $this->json(['error' => 'Validación', 'message' => 'El campo file es obligatorio'], 422);
            }

            $stored = $this->uploads->store($file, 'teams');

            return $this->json(['success' => true, 'data' => $stored, 'message' => 'Imagen subida'], 201);
        } catch (UploadException $e) {
            return $this->json(['error' => 'Validación', 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'No se pudo subir la imagen', 'message' => $e->getMessage()], 500);
        }
    }

    public function show(string $path)
    {
        $absolute = $this->uploads->path($path);

        if ($absolute === null || !is_file($absolute)) {
            return $this->json(['error' => 'Archivo no encontrado'], 404);
        }

        return Response::file($absolute);
    }
}