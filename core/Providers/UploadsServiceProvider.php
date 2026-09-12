<?php

namespace Apollo\Core\Providers;

use Apollo\Core\Container\ServiceProvider;
use Apollo\Core\Uploads\Support\UploadManager;

/**
 * Módulo uploads: bindings inertes hasta que se usan (subida de archivos).
 */
class UploadsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(UploadManager::class, function ($app) {
            return new UploadManager(config('uploads', []));
        });

        // Alias 'uploads' → manager (para helpers/facades)
        $this->container->alias(UploadManager::class, 'uploads');
    }
}