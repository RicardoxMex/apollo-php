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
     * Listing with filters (mirror of the frontend explore).
     */
    public function filter(array $filters, int $perPage = 20, int $page = 1): array
    {
        $query = $this->builder();

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['sport'])) {
            $query->where('sport', $filters['sport']);
        }
        if (!empty($filters['visibility'])) {
            $query->where('visibility', $filters['visibility']);
        }
        if (!empty($filters['q'])) {
            $query->where('title', 'LIKE', "%{$filters['q']}%");
        }
        if (!empty($filters['organizer_id'])) {
            $query->where('organizer_id', $filters['organizer_id']);
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