<?php
// apps/users/Models/User.php

namespace Apps\Users\Models;

use Apollo\Core\Database\Repository\BaseRepository;

class User extends BaseRepository {
    protected string $table = 'users';
}