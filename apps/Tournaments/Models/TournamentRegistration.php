<?php

namespace Apps\Tournaments\Models;

use Apollo\Core\Database\Model;
use Apps\ApolloAuth\Models\User;

class TournamentRegistration extends Model
{
    protected $table = 'tournament_registrations';

    protected $fillable = [
        'tournament_id',
        'team_id',
        'player_id',
        'applicant_id',
        'status',
        'message',
        'decided_by',
        'decided_at',
    ];

    protected $dates = [
        'decided_at',
        'created_at',
        'updated_at',
    ];

    /**
     * Torneo al que se inscribe
     */
    public function tournament()
    {
        return $this->belongsTo(Tournament::class, 'tournament_id');
    }

    /**
     * Equipo solicitante (null en torneos individuales)
     */
    public function team()
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    /**
     * Jugador solicitante (torneos individuales; excluyente con team)
     */
    public function player()
    {
        return $this->belongsTo(Player::class, 'player_id');
    }

    /**
     * Usuario que realiza la inscripción (perfil público)
     */
    public function applicant()
    {
        return $this->belongsTo(User::class, 'applicant_id');
    }

    /**
     * Organizador que aceptó/rechazó
     */
    public function decidedBy()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * Participante materializado (existe cuando la inscripción fue aceptada)
     */
    public function participant()
    {
        return $this->hasOne(TournamentParticipant::class, 'registration_id');
    }
}