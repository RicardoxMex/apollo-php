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
    |   allowed_mimes extensiones permitidas; default imágenes; vacío = sin restricción
    |   overwrite     si false, nombres en conflicto se renombran con sufijo
    |
    */
    'driver' => env('UPLOADS_DRIVER', 'local'),
    'root' => env('UPLOADS_PATH', 'storage/uploads'),
    'url_prefix' => '/uploads',
    'max_size' => (int) env('UPLOADS_MAX_SIZE', 10240),
    // Whitelist segura por defecto (imágenes; sin svg) aunque UPLOADS_ALLOWED_MIMES
    // esté vacío. Defensa en profundidad D7 (R-PERIM-02).
    'allowed_mimes' => env('UPLOADS_ALLOWED_MIMES') ?: 'jpeg,jpg,png,webp,gif',
    'overwrite' => false,
];