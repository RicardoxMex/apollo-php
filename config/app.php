
<?php

return [
    'name' => 'ApolloPHP',
    'env' => env('APP_ENV', 'production'),
    'debug' => env('APP_DEBUG', false),
    'providers' => [
        // Providers base del framework
        // ApolloPHP\Database\DatabaseServiceProvider::class,
        // ApolloPHP\Http\HttpServiceProvider::class,
    ],
    'modules' => [
        'path' => 'modules',
        'autoload' => true,
    ],
];