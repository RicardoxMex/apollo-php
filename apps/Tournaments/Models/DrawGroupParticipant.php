<?php

namespace Apps\Tournaments\Models;

use Apollo\Core\Database\Model;

/**
 * Pivote draw_group_participants (PK compuesta, sin id propio).
 */
class DrawGroupParticipant extends Model
{
    protected $table = 'draw_group_participants';

    protected $fillable = [
        'group_id',
        'tournament_participant_id',
        'position',
    ];

    protected $casts = [
        'position' => 'integer',
    ];
}