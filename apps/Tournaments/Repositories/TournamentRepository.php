<?php

namespace Apps\Tournaments\Repositories;

use Apollo\Core\Database\Repository\BaseRepository;

class TournamentRepository extends BaseRepository
{
    protected string $table = 'tournaments';

    protected array $searchable = [
        'title',
        'sport',
        'location',
    ];

    /**
     * Listado con filtros (espejo del explore del frontend).
     */
    public function filtrar(array $filtros, int $perPage = 20, int $page = 1): array
    {
        $query = $this->builder();

        if (!empty($filtros['status'])) {
            $query->where('status', $filtros['status']);
        }
        if (!empty($filtros['sport'])) {
            $query->where('sport', $filtros['sport']);
        }
        if (!empty($filtros['visibility'])) {
            $query->where('visibility', $filtros['visibility']);
        }
        if (!empty($filtros['q'])) {
            $query->where('title', 'LIKE', "%{$filtros['q']}%");
        }
        if (!empty($filtros['organizer_id'])) {
            $query->where('organizer_id', $filtros['organizer_id']);
        }

        $total = (int) $query->count();
        $items = $query
            ->orderBy('created_at', 'DESC')
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->get();

        return [
            'data' => $items,
            'meta' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => (int) ceil($total / $perPage),
            ],
        ];
    }
}