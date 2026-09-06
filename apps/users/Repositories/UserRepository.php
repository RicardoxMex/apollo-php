<?php
// apps/Users/Repositories/UserRepository.php

namespace Apps\Users\Repositories;

use Apollo\Core\Database\Repository\BaseRepository;

class UserRepository extends BaseRepository
{
    protected string $table = 'users';

    // Campos en los que se puede buscar
    protected array $searchable = [
        'name',
        'email',
        'username'
    ];
}