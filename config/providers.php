<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Core Service Providers
    |--------------------------------------------------------------------------
    |
    | Los providers del core que se cargan automáticamente
    |
    */
    'core' => [
        \Apollo\Core\Providers\AppServiceProvider::class,
        \Apollo\Core\Providers\RealtimeServiceProvider::class,
        \Apollo\Core\Providers\UploadsServiceProvider::class,
        \Apollo\Core\Providers\MailServiceProvider::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Application Service Providers
    |--------------------------------------------------------------------------
    |
    | Los providers específicos de la aplicación
    |
    */
    'app' => [
        // Aquí puedes agregar providers personalizados
    ],
];