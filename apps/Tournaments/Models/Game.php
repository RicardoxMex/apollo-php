<?php

namespace Apps\Tournaments\Models;

use Apollo\Core\Database\Model;

/**
 * Partido oficial (tabla `matches`).
 * Nombre `Game` porque `match` es una palabra reservada de PHP 8 y no puede
 * usarse como nombre de clase.
 */
class Game extends Model
{
    protected $table = 'matches';

    protected $fillable = [
        'tournament_id',
        'draw_match_id',
        'round_number',
        'match_number',
        'participant_a_id',
        'participant_b_id',
        'winner_participant_id',
        'status',
        'scheduled_at',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'round_number' => 'integer',
        'match_number' => 'integer',
    ];

    protected $dates = [
        'scheduled_at',
        'started_at',
        'finished_at',
        'created_at',
        'updated_at',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class, 'tournament_id');
    }

    /**
     * Enfrentamiento del bracket que originó el partido (si existe)
     */
    public function drawMatch()
    {
        return $this->belongsTo(DrawMatch::class, 'draw_match_id');
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
     * Marcadores por stat configurada (match_scores)
     */
    public function scores()
    {
        return $this->hasMany(MatchScore::class, 'match_id');
    }

    /**
     * Stats por jugador/participante (match_player_stats)
     */
    public function playerStats()
    {
        return $this->hasMany(MatchPlayerStat::class, 'match_id');
    }
}