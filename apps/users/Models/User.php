<?php
// apps/users/Models/User.php

namespace Apps\Users\Models;

use Apollo\Core\Database\Repository\BaseRepository;

class User extends BaseRepository {
    protected string $table = 'users';
    
    // Campos en los que se puede buscar
    protected array $searchable = [
        'name',
        'email',
        'username'
    ];
}