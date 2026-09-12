<?php
// core/Uploads/Support/UploadManager.php

namespace Apollo\Core\Uploads\Support;

use Apollo\Core\Uploads\Contracts\Disk;
use Apollo\Core\Uploads\Exceptions\UploadException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Servicio central del módulo Uploads.
 *
 * - Resuelve el driver (config uploads.driver; v1: local).
 * - Almacena archivos con nombres únicos y saneados.
 * - Aplica límites server-side SIEMPRE (max_size KB + allowed_mimes), aunque
 *   el llamador no haya validado (defensa en profundidad, D7).
 * - Resuelve el root relativo contra el base path del proyecto.
 */
final class UploadManager
{
    private ?Disk $disk = null;

    public function __construct(private readonly array $config = [])
    {
    }

    public function config(): UploadConfig
    {
        return new UploadConfig($this->config);
    }

    public function disk(): Disk
    {
        if ($this->disk !== null) {
            return $this->disk;
        }

        $cfg = $this->config();
        $root = $this->resolveRoot($cfg->root());

        return $this->disk = match ($cfg->driver()) {
            'local' => new LocalDisk($root, $cfg->urlPrefix()),
            default => throw new InvalidArgumentException(
                "Driver de uploads desconocido: {$cfg->driver()}"
            ),
        };
    }

    /**
     * Almacenar con nombre único generado.
     *
     * @param  UploadedFile|array  $file  VO o entrada de $_FILES
     * @param  array<string, mixed>  $options  (sin uso reservado; extensibilidad)
     * @return array{name: string, path: string, url: string, size: int, mime: string}
     */
    public function store(UploadedFile|array $file, string $directory = '', array $options = []): array
    {
        $uploaded = $this->wrap($file);
        $this->assertAllowed($uploaded);

        $name = $uploaded->hashName();
        $extension = $uploaded->extension();

        if ($extension !== '') {
            $name .= '.' . $extension;
        }

        return $this->storeAs($uploaded, $directory, $name, $options);
    }

    /**
     * Almacenar con nombre explícito (saneado; si ya existe y overwrite=false,
     * se genera un nombre único).
     *
     * @param  UploadedFile|array  $file
     * @param  array<string, mixed>  $options
     * @return array{name: string, path: string, url: string, size: int, mime: string}
     */
    public function storeAs(UploadedFile|array $file, string $directory, string $name, array $options = []): array
    {
        $uploaded = $this->wrap($file);
        $this->assertAllowed($uploaded);

        $name = $this->sanitizeName($name);

        if ($name === '') {
            throw new UploadException('Nombre de archivo inválido.');
        }

        $directory = trim(str_replace('\\', '/', $directory), '/');
        $path = $directory !== '' ? $directory . '/' . $name : $name;

        $disk = $this->disk();

        if ($disk->exists($path) && !$this->config()->overwrite()) {
            $name = $this->uniqueName($path, $name);
            $path = $directory !== '' ? $directory . '/' . $name : $name;
        }

        if (!$disk->putFile($uploaded->path(), $path)) {
            throw new RuntimeException('No se pudo almacenar el archivo.');
        }

        return [
            'name' => $name,
            'path' => $path,
            'url' => $disk->url($path),
            'size' => $uploaded->size(),
            'mime' => $uploaded->mime(),
        ];
    }

    public function get(string $path): ?string
    {
        return $this->disk()->get($path);
    }

    public function exists(string $path): bool
    {
        return $this->disk()->exists($path);
    }

    public function delete(string $path): bool
    {
        return $this->disk()->delete($path);
    }

    public function url(string $path): string
    {
        return $this->disk()->url($path);
    }

    /**
     * Path absoluto validado (null = inseguro/inexistente).
     */
    public function path(string $path): ?string
    {
        return $this->disk()->path($path);
    }

    private function wrap(UploadedFile|array $file): UploadedFile
    {
        if ($file instanceof UploadedFile) {
            return $file;
        }

        $uploaded = UploadedFile::fromArray($file);

        if ($uploaded === null) {
            throw new InvalidArgumentException('Entrada de archivo inválida.');
        }

        return $uploaded;
    }

    /**
     * Límites de config SIEMPRE aplicados (D7).
     */
    private function assertAllowed(UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new UploadException('El archivo no es una subida válida.');
        }

        $cfg = $this->config();
        $maxBytes = $cfg->maxSizeKb() * 1024;

        if ($file->size() > $maxBytes) {
            throw new UploadException(
                "El archivo supera el tamaño máximo de {$cfg->maxSizeKb()} KB."
            );
        }

        $allowed = $cfg->allowedMimes();

        if ($allowed === []) {
            return;
        }

        if (!in_array($file->extension(), $allowed, true) || !$this->mimeMatches($file->mime(), $allowed)) {
            throw new UploadException('Tipo de archivo no permitido.');
        }
    }

    /**
     * Verificar que el MIME real (sniff) sea compatible con alguna extensión
     * permitida. Extensiones sin mapa confían solo en la extensión.
     */
    private function mimeMatches(string $mime, array $extensions): bool
    {
        $map = [
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'gif' => ['image/gif'],
            'webp' => ['image/webp'],
            'svg' => ['image/svg+xml'],
            'bmp' => ['image/bmp'],
            'ico' => ['image/x-icon'],
            'pdf' => ['application/pdf'],
            'zip' => ['application/zip'],
            'rar' => ['application/vnd.rar'],
            'txt' => ['text/plain'],
            'csv' => ['text/csv', 'text/plain'],
            'md' => ['text/markdown', 'text/plain'],
            'json' => ['application/json', 'text/plain'],
            'xml' => ['application/xml', 'text/xml'],
            'doc' => ['application/msword'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'xls' => ['application/vnd.ms-excel'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'ppt' => ['application/vnd.ms-powerpoint'],
            'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation'],
            'mp3' => ['audio/mpeg'],
            'mp4' => ['video/mp4'],
            'webm' => ['video/webm'],
            'mov' => ['video/quicktime'],
        ];

        foreach ($extensions as $extension) {
            $expected = $map[$extension] ?? [];

            if ($expected === []) {
                continue; // sin mapa: confiar en la extensión
            }

            if (in_array($mime, $expected, true)) {
                return true;
            }
        }

        return false;
    }

    private function sanitizeName(string $name): string
    {
        $name = str_replace('\\', '/', $name);

        // Solo el último segmento; ".." nunca entra.
        if (str_contains($name, '/')) {
            $name = basename($name);
        }

        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?? '';

        return trim($name, ' ._');
    }

    private function uniqueName(string $path, string $name): string
    {
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $base = pathinfo($name, PATHINFO_FILENAME);
        $dir = dirname($path) === '.' ? '' : dirname($path);

        $candidate = $name;
        $i = 1;

        while ($this->disk()->exists(($dir !== '' ? $dir . '/' : '') . $candidate)) {
            $candidate = $base . '_' . $i . ($extension !== '' ? '.' . $extension : '');
            $i++;
        }

        return $candidate;
    }

    private function resolveRoot(string $root): string
    {
        $isAbsolute = str_starts_with($root, '/')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $root) === 1;

        if ($isAbsolute) {
            return $root;
        }

        $base = app('path');

        return rtrim((string) $base, '/\\') . '/' . ltrim($root, '/\\');
    }
}