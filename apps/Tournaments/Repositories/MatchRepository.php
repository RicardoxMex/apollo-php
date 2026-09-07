<?php

namespace Apps\Tournaments\Repositories;

use Apollo\Core\Database\Repository\BaseRepository;

class MatchRepository extends BaseRepository
{
    protected string $table = 'matches';

    /**
     * Partidos del torneo con marcadores y stats por jugador.
     */
    public function listWithDetails(int $tournamentId): array
    {
        $matches = $this->builder()
            ->where('tournament_id', $tournamentId)
            ->orderBy('round_number', 'ASC')
            ->orderBy('match_number', 'ASC')
            ->get();

        $builder = new \Apollo\Core\Database\QueryBuilder(
            \Apollo\Core\Database\Connection\DatabaseManager::getConnection(),
            'match_scores'
        );
        $scores = $builder->whereIn('match_id', array_column($matches, 'id'))->get();
        $scoresPorMatch = [];
        foreach ($scores as $s) {
            $scoresPorMatch[$s['match_id']][] = $s;
        }

        foreach ($matches as &$m) {
            $m['scores'] = $scoresPorMatch[$m['id']] ?? [];
        }

        return $matches;
    }
}