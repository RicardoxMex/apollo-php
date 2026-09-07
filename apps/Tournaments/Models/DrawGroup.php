<?php

namespace Apps\Tournaments\Models;

use Apollo\Core\Database\Model;

class DrawGroup extends Model
{
    protected $table = 'draw_groups';

    protected $fillable = [
        'draw_id',
        'name',
        'position',
    ];

    protected $casts = [
        'position' => 'integer',
    ];

    public function draw()
    {
        return $this->belongsTo(Draw::class, 'draw_id');
    }

    /**
     * Participantes del grupo (N—M vía draw_group_participants)
     */
    public function participants()
    {
        return $this->belongsToMany(
            TournamentParticipant::class,
            'draw_group_participants',
            'group_id',
            'tournament_participant_id'
        );
    }
}