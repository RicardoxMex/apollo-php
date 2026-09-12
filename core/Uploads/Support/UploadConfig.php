<?php
// core/Uploads/Support/UploadConfig.php

namespace Apollo\Core\Uploads\Support;

/**
 * Accessor tipado sobre el array de config/uploads.php (mismo patrón que
 * RealtimeConfig). Los defaults viven aquí y en config/uploads.php.
 */
final class UploadConfig
{
    public function __construct(private readonly array $config = [])
    {
    }

    public function driver(): string
    {
        return $this->config['driver'] ?? 'local';
    }

    /**
     * Root del disco. Relativo → se resuelve contra el base path del proyecto
     * (app('path')); absoluto (o con drive en Windows) se usa tal cual.
     */
    public function root(): string
    {
        return $this->config['root'] ?? 'storage/uploads';
    }

    public function urlPrefix(): string
    {
        return $this->config['url_prefix'] ?? '/uploads';
    }

    /**
     * Tamaño máximo en KB (default 10 MB).
     */
    public function maxSizeKb(): int
    {
        return (int) ($this->config['max_size'] ?? 10240);
    }

    /**
     * Extensiones permitidas. Acepta array o string CSV desde env.
     * Vacío = sin restricción de tipo (no recomendado en producción).
     *
     * @return list<string>
     */
    public function allowedMimes(): array
    {
        $mimes = $this->config['allowed_mimes'] ?? null;

        if (is_string($mimes)) {
            $mimes = $mimes === '' ? [] : explode(',', $mimes);
        }

        if (!is_array($mimes)) {
            return [];
        }

        return array_values(array_unique(array_map('strtolower', array_map('trim', $mimes))));
    }

    public function overwrite(): bool
    {
        return (bool) ($this->config['overwrite'] ?? false);
    }

    public function all(): array
    {
        return $this->config;
    }
}