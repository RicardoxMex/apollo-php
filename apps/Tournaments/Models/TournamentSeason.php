<?php

namespace Apps\Tournaments\Models;

use Apollo\Core\Database\Model;

class TournamentSeason extends Model
{
    protected $table = 'tournament_seasons';

    protected $fillable = [
        'name',
        'starts_at',
        'ends_at',
    ];

    protected $dates = [
        'starts_at',
        'ends_at',
        'created_at',
    ];

    /**
     * Torneos de la temporada
     */
    public function tournaments()
    {
        return $this->hasMany(Tournament::class, 'season_id');
    }
}