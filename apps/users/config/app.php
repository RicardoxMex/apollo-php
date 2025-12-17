<?php
// apps/users/config/app.php
return [
    'name' => 'Users App',
    'route_prefix' => 'api/users',
    'middleware' => ['api'],
    'models' => [
        'User'
    ]
];