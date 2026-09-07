<?php

namespace Apps\Tournaments\Models;

use Apollo\Core\Database\Model;

class TournamentStat extends Model
{
    protected $table = 'tournament_stats';

    protected $fillable = [
        'tournament_id',
        'label',
        'type',
        'per_player',
    ];

    protected $casts = [
        'per_player' => 'boolean',
    ];

    protected $dates = [
        'created_at',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class, 'tournament_id');
    }
}