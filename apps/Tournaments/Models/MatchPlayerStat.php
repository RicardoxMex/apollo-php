<?php

namespace Apps\Tournaments\Models;

use Apollo\Core\Database\Model;

class MatchPlayerStat extends Model
{
    protected $table = 'match_player_stats';

    protected $fillable = [
        'match_id',
        'participant_id',
        'player_id',
        'stat_id',
        'value',
    ];

    protected $casts = [
        'value' => 'float',
    ];

    public function match()
    {
        return $this->belongsTo(Game::class, 'match_id');
    }

    public function participant()
    {
        return $this->belongsTo(TournamentParticipant::class, 'participant_id');
    }

    public function player()
    {
        return $this->belongsTo(Player::class, 'player_id');
    }

    public function stat()
    {
        return $this->belongsTo(TournamentStat::class, 'stat_id');
    }
}