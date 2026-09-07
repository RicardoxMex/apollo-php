<?php

namespace Apps\Tournaments\Repositories;

use Apollo\Core\Database\Repository\BaseRepository;

class PlayerRepository extends BaseRepository
{
    protected string $table = 'players';

    protected array $searchable = [
        'name',
        'jersey_number',
    ];
}