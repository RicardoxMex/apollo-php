<?php

namespace Apps\Tournaments\Models;

use Apollo\Core\Database\Model;
use Apps\ApolloAuth\Models\User;

class Team extends Model
{
    protected $table = 'teams';

    protected $fillable = [
        'name',
        'contact',
        'image',
    ];

    protected $dates = [
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /**
     * Capitanes del equipo (N—M vía team_captains)
     */
    public function captains()
    {
        return $this->belongsToMany(User::class, 'team_captains', 'team_id', 'user_id');
    }

    /**
     * Jugadores del equipo (N—M vía team_players, con pivote jersey_number/left_at)
     */
    public function players()
    {
        return $this->belongsToMany(Player::class, 'team_players', 'team_id', 'player_id');
    }

    /**
     * Inscripciones del equipo en torneos
     */
    public function registrations()
    {
        return $this->hasMany(TournamentRegistration::class, 'team_id');
    }
}