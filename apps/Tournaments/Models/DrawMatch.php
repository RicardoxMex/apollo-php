<?php

namespace Apps\Tournaments\Models;

use Apollo\Core\Database\Model;

class DrawMatch extends Model
{
    protected $table = 'draw_matches';

    protected $fillable = [
        'round_id',
        'match_number',
        'participant_a_id',
        'participant_b_id',
        'winner_participant_id',
        'status',
        'scheduled_at',
        'next_match_id',
        'next_slot',
    ];

    protected $dates = [
        'scheduled_at',
    ];

    public function round()
    {
        return $this->belongsTo(DrawRound::class, 'round_id');
    }

    public function participantA()
    {
        return $this->belongsTo(TournamentParticipant::class, 'participant_a_id');
    }

    public function participantB()
    {
        return $this->belongsTo(TournamentParticipant::class, 'participant_b_id');
    }

    public function winner()
    {
        return $this->belongsTo(TournamentParticipant::class, 'winner_participant_id');
    }

    /**
     * Siguiente enfrentamiento en el bracket (avance del ganador)
     */
    public function nextMatch()
    {
        return $this->belongsTo(DrawMatch::class, 'next_match_id');
    }
}