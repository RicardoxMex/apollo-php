<?php

namespace ApolloAuth;

use ApolloPHP\Core\Module as BaseModule;

class Module extends BaseModule
{
    public function boot(): void
    {
        
        parent::boot();

        // Cargar helpers
        $this->loadHelpers();

        // Publicar configuración si no existe
        $this->publishConfig();

        // Publicar migraciones si no existen
        $this->publishMigrations();
    }

    protected function loadHelpers(): void
    {
        if (file_exists($this->getPath() . '/helpers.php')) {
            require_once $this->getPath() . '/helpers.php';
        }
    }

    protected function publishConfig(): void
    {
        $source = $this->getPath() . '/config.php';
        $destination = $this->app->configPath('auth.php');

        if (!file_exists($destination)) {
            @mkdir(dirname($destination), 0755, true);
            copy($source, $destination);
        }
    }

    protected function publishMigrations(): void
    {
        $migrationsPath = $this->getPath() . '/database/migrations';
        $appMigrationsPath = $this->app->databasePath('migrations');

        @mkdir($appMigrationsPath, 0755, true);

        foreach (glob($migrationsPath . '/*.php') as $migration) {
            $filename = basename($migration);
            $dest = $appMigrationsPath . '/' . $filename;

            if (!file_exists($dest)) {
                copy($migration, $dest);
            }
        }
    }
}