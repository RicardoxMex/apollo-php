<?php

namespace Apps\Tournaments\Repositories;

use Apollo\Core\Database\Repository\BaseRepository;

class SeasonRepository extends BaseRepository
{
    protected string $table = 'tournament_seasons';

    protected array $searchable = [
        'name',
    ];
}