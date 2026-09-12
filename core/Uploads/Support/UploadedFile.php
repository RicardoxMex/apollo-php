<?php
// core/Uploads/Support/UploadedFile.php

namespace Apollo\Core\Uploads\Support;

/**
 * Value Object sobre una entrada de $_FILES (o array equivalente).
 *
 * Centraliza: validez de la subida, extensión saneada, MIME (sniff con
 * finfo cuando está disponible) y el almacenamiento delegando en el
 * UploadManager del container.
 */
final class UploadedFile
{
    public function __construct(
        private readonly string $originalName,
        private readonly string $tmpPath,
        private readonly ?string $mimeType = null,
        private readonly int $size = 0,
        private readonly int $error = UPLOAD_ERR_OK,
    ) {
    }

    /**
     * Envolver una entrada de $_FILES. Devuelve null si no tiene forma de archivo.
     * Los arrays multi-file (name[]/tmp_name[]) NO se envuelven aquí: los normaliza Request.
     */
    public static function fromArray(array $file): ?self
    {
        if (!isset($file['name'], $file['tmp_name'])) {
            return null;
        }

        if (is_array($file['name']) || is_array($file['tmp_name'])) {
            return null;
        }

        return new self(
            (string) $file['name'],
            (string) $file['tmp_name'],
            isset($file['type']) ? (string) $file['type'] : null,
            isset($file['size']) ? (int) $file['size'] : 0,
            isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_OK,
        );
    }

    public function originalName(): string
    {
        return $this->originalName;
    }

    /**
     * MIME declarado por el cliente (no confiable; usar mime() para sniff).
     */
    public function clientMime(): ?string
    {
        return $this->mimeType;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function error(): int
    {
        return $this->error;
    }

    /**
     * Path temporal de la subida.
     */
    public function path(): string
    {
        return $this->tmpPath;
    }

    /**
     * Subida válida: sin error PHP y el archivo existe en disco.
     * (is_file cubre archivos creados en tests sin servidor web.)
     */
    public function isValid(): bool
    {
        if ($this->error !== UPLOAD_ERR_OK) {
            return false;
        }

        if ($this->tmpPath === '') {
            return false;
        }

        return is_uploaded_file($this->tmpPath) || is_file($this->tmpPath);
    }

    /**
     * Extensión del nombre original, minúscula y saneada (solo [a-z0-9]).
     */
    public function extension(): string
    {
        $ext = strtolower(pathinfo($this->originalName, PATHINFO_EXTENSION));

        if (!preg_match('/^[a-z0-9]+$/', $ext)) {
            return '';
        }

        return $ext;
    }

    /**
     * MIME real: sniff con finfo; fallback al MIME declarado por el cliente.
     */
    public function mime(): string
    {
        if (function_exists('finfo_open') && is_file($this->tmpPath)) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);

            if ($finfo !== false) {
                $mime = finfo_file($finfo, $this->tmpPath);
                finfo_close($finfo);

                if ($mime !== false && $mime !== '') {
                    return $mime;
                }
            }
        }

        return $this->mimeType ?? 'application/octet-stream';
    }

    /**
     * Nombre aleatorio para almacenar (sin extensión).
     */
    public function hashName(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * Almacenar con nombre generado único. Delega en el UploadManager.
     *
     * @return array{name: string, path: string, url: string, size: int, mime: string}
     */
    public function store(string $directory = '', array $options = []): array
    {
        return app(UploadManager::class)->store($this, $directory, $options);
    }

    /**
     * Almacenar con nombre explícito. Delega en el UploadManager.
     *
     * @return array{name: string, path: string, url: string, size: int, mime: string}
     */
    public function storeAs(string $directory, string $name, array $options = []): array
    {
        return app(UploadManager::class)->storeAs($this, $directory, $name, $options);
    }

    /**
     * Mover la subida a un path absoluto arbitrario (uso avanzado).
     */
    public function moveTo(string $path): bool
    {
        $dir = dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        if (is_uploaded_file($this->tmpPath)) {
            return move_uploaded_file($this->tmpPath, $path);
        }

        return rename($this->tmpPath, $path);
    }
}