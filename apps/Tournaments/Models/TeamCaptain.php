<?php

namespace Apps\Tournaments\Models;

use Apollo\Core\Database\Model;

/**
 * Pivote team_captains (PK compuesta, sin id propio).
 */
class TeamCaptain extends Model
{
    protected $table = 'team_captains';

    protected $fillable = [
        'team_id',
        'user_id',
    ];
}