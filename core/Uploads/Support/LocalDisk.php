<?php
// core/Uploads/Support/LocalDisk.php

namespace Apollo\Core\Uploads\Support;

use Apollo\Core\Uploads\Contracts\Disk;

/**
 * Disco local: almacena en un directorio del servidor (por defecto
 * `storage/uploads`, fuera de public/ y gitignored).
 *
 * Seguridad: todo path se normaliza por segmentos; ".." se rechaza; las
 * lecturas se contienen dentro del root vía realpath.
 */
final class LocalDisk implements Disk
{
    public function __construct(
        private readonly string $root,
        private readonly string $urlPrefix = '/uploads',
    ) {
    }

    public function root(): string
    {
        return rtrim($this->root, '/\\');
    }

    public function put(string $path, string $content): bool
    {
        $absolute = $this->path($path);

        if ($absolute === null) {
            return false;
        }

        if (!$this->ensureDirectory(dirname($absolute))) {
            return false;
        }

        return file_put_contents($absolute, $content) !== false;
    }

    public function putFile(string $sourcePath, string $path): bool
    {
        $absolute = $this->path($path);

        if ($absolute === null || !is_file($sourcePath)) {
            return false;
        }

        if (!$this->ensureDirectory(dirname($absolute))) {
            return false;
        }

        if (is_uploaded_file($sourcePath)) {
            return move_uploaded_file($sourcePath, $absolute);
        }

        // Archivos no subidos por HTTP (tests, CLI): copiar sin destruir el origen.
        return copy($sourcePath, $absolute);
    }

    public function get(string $path): ?string
    {
        $absolute = $this->path($path);

        if ($absolute === null || !is_file($absolute)) {
            return null;
        }

        $content = file_get_contents($absolute);

        return $content === false ? null : $content;
    }

    public function exists(string $path): bool
    {
        $absolute = $this->path($path);

        return $absolute !== null && is_file($absolute);
    }

    public function delete(string $path): bool
    {
        $absolute = $this->path($path);

        if ($absolute === null || !is_file($absolute)) {
            return false;
        }

        return unlink($absolute);
    }

    public function path(string $path): ?string
    {
        $normalized = $this->normalize($path);

        if ($normalized === null) {
            return null;
        }

        $absolute = $this->root() . DIRECTORY_SEPARATOR . $normalized;
        $realRoot = realpath($this->root());

        if ($realRoot === false) {
            // El root aún no existe: los segmentos ya validaron el path.
            return $absolute;
        }

        // Comparar siempre con separadores uniformes (Windows mezcla \ y /).
        $absoluteFlat = str_replace('\\', '/', $absolute);
        $realRootFlat = str_replace('\\', '/', $realRoot);

        if (!str_starts_with($absoluteFlat, $realRootFlat . '/')) {
            return null;
        }

        $real = realpath($absolute);

        return $real !== false ? $real : $absolute;
    }

    public function url(string $path): string
    {
        $normalized = $this->normalize($path);

        if ($normalized === null) {
            return '';
        }

        return rtrim($this->urlPrefix, '/') . '/' . $normalized;
    }

    public function size(string $path): int
    {
        $absolute = $this->path($path);

        return $absolute !== null && is_file($absolute) ? (int) filesize($absolute) : 0;
    }

    public function mime(string $path): string
    {
        $absolute = $this->path($path);

        if ($absolute === null || !is_file($absolute) || !function_exists('finfo_open')) {
            return 'application/octet-stream';
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return 'application/octet-stream';
        }

        $mime = finfo_file($finfo, $absolute);
        finfo_close($finfo);

        return $mime !== false && $mime !== '' ? $mime : 'application/octet-stream';
    }

    /**
     * Normalizar "a/b/../c" → null (traversal), "a//b/./c" → "a/b/c".
     */
    private function normalize(string $path): ?string
    {
        $path = str_replace('\\', '/', $path);
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                return null;
            }

            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    private function ensureDirectory(string $dir): bool
    {
        return is_dir($dir) || mkdir($dir, 0775, true);
    }
}