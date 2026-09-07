<?php

namespace Apps\Tournaments\Models;

use Apollo\Core\Database\Model;

class TournamentPrize extends Model
{
    protected $table = 'tournament_prizes';

    protected $fillable = [
        'tournament_id',
        'position',
        'amount',
        'currency',
        'label',
    ];

    protected $casts = [
        'position' => 'integer',
        'amount' => 'float',
    ];

    protected $dates = [
        'created_at',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class, 'tournament_id');
    }
}