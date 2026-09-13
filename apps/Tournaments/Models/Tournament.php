<?php

namespace Apps\Tournaments\Models;

use Apollo\Core\Database\Model;
use Apps\ApolloAuth\Models\User;

class Tournament extends Model
{
    protected $table = 'tournaments';

    protected $fillable = [
        'organizer_id',
        'season_id',
        'title',
        'sport',
        'description',
        'location',
        'is_online',
        'image',
        'status',
        'format',
        'max_participants',
        'clasificados_eliminacion',
        'ida_vuelta',
        'is_individual',
        'start_date',
        'end_date',
        'registration_deadline',
        'registration_fee',
        'currency',
        'visibility',
        'minimum_age',
        'rules',
        'max_substitutes',
        'players_per_team',
    ];

    protected $casts = [
        'is_online' => 'boolean',
        'is_individual' => 'boolean',
        'max_participants' => 'integer',
        'clasificados_eliminacion' => 'integer',
        'ida_vuelta' => 'boolean',
        'registration_fee' => 'float',
        'minimum_age' => 'integer',
        'max_substitutes' => 'integer',
        'players_per_team' => 'integer',
    ];

    protected $dates = [
        'start_date',
        'end_date',
        'registration_deadline',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /**
     * Organizador (Usuario que crea y gestiona el torneo)
     */
    public function organizer()
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    /**
     * Temporada a la que pertenece (opcional)
     */
    public function season()
    {
        return $this->belongsTo(TournamentSeason::class, 'season_id');
    }

    /**
     * Premios del torneo
     */
    public function prizes()
    {
        return $this->hasMany(TournamentPrize::class);
    }

    /**
     * Stats configurables del torneo (marcadores por stat)
     */
    public function stats()
    {
        return $this->hasMany(TournamentStat::class);
    }

    /**
     * Solicitudes de inscripción (pendientes/aceptadas/rechazadas/canceladas)
     */
    public function registrations()
    {
        return $this->hasMany(TournamentRegistration::class);
    }

    /**
     * Participantes resueltos (inscripciones aceptadas materializadas)
     */
    public function participants()
    {
        return $this->hasMany(TournamentParticipant::class);
    }

    /**
     * Sorteos (historial versionado; el activo es el de mayor version)
     */
    public function draws()
    {
        return $this->hasMany(Draw::class);
    }

    /**
     * Partidos oficiales (fuente única de resultados)
     */
    public function matches()
    {
        return $this->hasMany(Game::class);
    }
}