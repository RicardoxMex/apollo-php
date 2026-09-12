<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Uploads — subida de archivos
    |--------------------------------------------------------------------------
    |
    | Módulo opcional (core/Uploads). Claves:
    |
    |   driver        disco activo (v1: solo 'local')
    |   root          directorio de almacenamiento (relativo al proyecto o absoluto)
    |   url_prefix    prefijo de las URLs públicas generadas
    |   max_size      tamaño máximo en KB (default 10 MB)
    |   allowed_mimes extensiones permitidas; vacío = sin restricción
    |   overwrite     si false, nombres en conflicto se renombran con sufijo
    |
    */
    'driver' => env('UPLOADS_DRIVER', 'local'),
    'root' => env('UPLOADS_PATH', 'storage/uploads'),
    'url_prefix' => '/uploads',
    'max_size' => (int) env('UPLOADS_MAX_SIZE', 10240),
    'allowed_mimes' => env('UPLOADS_ALLOWED_MIMES'),
    'overwrite' => false,
];