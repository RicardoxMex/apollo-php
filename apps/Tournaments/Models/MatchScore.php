<?php

namespace Apps\Tournaments\Models;

use Apollo\Core\Database\Model;

class MatchScore extends Model
{
    protected $table = 'match_scores';

    protected $fillable = [
        'match_id',
        'stat_id',
        'score_a',
        'score_b',
    ];

    protected $casts = [
        'score_a' => 'float',
        'score_b' => 'float',
    ];

    public function match()
    {
        return $this->belongsTo(Game::class, 'match_id');
    }

    public function stat()
    {
        return $this->belongsTo(TournamentStat::class, 'stat_id');
    }
}