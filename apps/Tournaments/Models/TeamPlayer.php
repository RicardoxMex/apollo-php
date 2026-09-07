<?php

namespace Apps\Tournaments\Models;

use Apollo\Core\Database\Model;

/**
 * Pivote team_players (PK compuesta, sin id propio).
 * Guarda el dorsal por equipo y el historial de membresía (joined_at/left_at).
 */
class TeamPlayer extends Model
{
    protected $table = 'team_players';

    protected $fillable = [
        'team_id',
        'player_id',
        'jersey_number',
        'joined_at',
        'left_at',
    ];

    protected $dates = [
        'joined_at',
        'left_at',
    ];
}