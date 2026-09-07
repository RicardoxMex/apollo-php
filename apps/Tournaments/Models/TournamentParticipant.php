<?php

namespace Apps\Tournaments\Models;

use Apollo\Core\Database\Model;

class TournamentParticipant extends Model
{
    protected $table = 'tournament_participants';

    protected $fillable = [
        'tournament_id',
        'registration_id',
        'team_id',
        'player_id',
        'seed',
    ];

    protected $casts = [
        'seed' => 'integer',
    ];

    protected $dates = [
        'created_at',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class, 'tournament_id');
    }

    /**
     * Inscripción aceptada que originó este participante
     */
    public function registration()
    {
        return $this->belongsTo(TournamentRegistration::class, 'registration_id');
    }

    public function team()
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    public function player()
    {
        return $this->belongsTo(Player::class, 'player_id');
    }
}