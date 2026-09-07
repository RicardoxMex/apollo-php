<?php

namespace Apps\Tournaments\Models;

use Apollo\Core\Database\Model;

class DrawRound extends Model
{
    protected $table = 'draw_rounds';

    protected $fillable = [
        'draw_id',
        'round_number',
        'name',
    ];

    protected $casts = [
        'round_number' => 'integer',
    ];

    public function draw()
    {
        return $this->belongsTo(Draw::class, 'draw_id');
    }

    /**
     * Enfrentamientos del bracket en esta ronda
     */
    public function matches()
    {
        return $this->hasMany(DrawMatch::class, 'round_id');
    }
}