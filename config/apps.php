<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Registered Applications
    |--------------------------------------------------------------------------
    |
    | Lista de aplicaciones que se cargarán automáticamente
    |
    */
    'registered' => [
        'ApolloAuth',
        'Users',
        'Products',
        'Tournaments',
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto Discovery
    |--------------------------------------------------------------------------
    |
    | Si está habilitado, el framework buscará automáticamente
    | aplicaciones en el directorio apps/
    |
    */
    'auto_discovery' => true,
];