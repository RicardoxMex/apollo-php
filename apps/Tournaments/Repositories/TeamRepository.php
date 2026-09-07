<?php

namespace Apps\Tournaments\Repositories;

use Apollo\Core\Database\Repository\BaseRepository;

class TeamRepository extends BaseRepository
{
    protected string $table = 'teams';

    protected array $searchable = [
        'name',
        'contact',
    ];
}