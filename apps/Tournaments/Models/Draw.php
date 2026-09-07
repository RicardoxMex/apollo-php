<?php

namespace Apps\Tournaments\Models;

use Apollo\Core\Database\Model;

class Draw extends Model
{
    protected $table = 'draws';

    protected $fillable = [
        'tournament_id',
        'type',
        'version',
        'generated_at',
    ];

    protected $casts = [
        'version' => 'integer',
    ];

    protected $dates = [
        'generated_at',
        'created_at',
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class, 'tournament_id');
    }

    /**
     * Grupos del sorteo (solo para type = groups)
     */
    public function groups()
    {
        return $this->hasMany(DrawGroup::class, 'draw_id');
    }

    /**
     * Rondas del bracket (solo para type = bracket)
     */
    public function rounds()
    {
        return $this->hasMany(DrawRound::class, 'draw_id');
    }
}