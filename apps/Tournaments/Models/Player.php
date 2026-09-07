<?php

namespace Apps\Tournaments\Models;

use Apollo\Core\Database\Model;
use Apps\ApolloAuth\Models\User;

class Player extends Model
{
    protected $table = 'players';

    protected $fillable = [
        'user_id',
        'name',
        'jersey_number',
        'birth_date',
    ];

    protected $casts = [
        'birth_date' => 'datetime',
    ];

    protected $dates = [
        'birth_date',
        'created_at',
        'updated_at',
    ];

    /**
     * Cuenta de plataforma opcional (perfil público del jugador)
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Equipos a los que pertenece (N—M vía team_players)
     */
    public function teams()
    {
        return $this->belongsToMany(Team::class, 'team_players', 'player_id', 'team_id');
    }

    /**
     * Inscripciones individuales en torneos
     */
    public function registrations()
    {
        return $this->hasMany(TournamentRegistration::class, 'player_id');
    }
}