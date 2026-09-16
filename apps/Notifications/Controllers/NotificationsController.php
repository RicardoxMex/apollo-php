<?php

namespace Apps\Notifications\Controllers;

use Apollo\Core\Http\Controller;
use Apollo\Core\Http\Request;
use Apollo\Core\Http\Response;
use Apollo\Core\Realtime\Notifications\MySqlNotificationRepository;

/**
 * REST API de notificaciones del usuario autenticado (guía websockets §13).
 * Repositorio concreto MySqlNotificationRepository (MySQL o SQLite).
 */
class NotificationsController extends Controller
{
    private MySqlNotificationRepository $repo;

    public function __construct()
    {
        $this->repo = new MySqlNotificationRepository();
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $userId = (int) $user->id;
        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min(100, (int) $request->query('perPage', 20)));

        // forUser() hidrata todas las notificaciones del usuario (volumen por
        // usuario) y el filtro se resuelve aquí: el filtro 'unread' del
        // repositorio genera `read_at = NULL`, que nunca coincide.
        $items = $this->repo->forUser($userId);

        $unreadCount = count(array_filter($items, fn (array $row) => empty($row['read_at'])));

        if ((bool) $request->query('unread', false)) {
            $items = array_values(array_filter($items, fn (array $row) => empty($row['read_at'])));
        }

        $total = count($items);

        return $this->json([
            'success' => true,
            'data' => array_slice($items, ($page - 1) * $perPage, $perPage),
            'meta' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => (int) ceil($total / $perPage),
            ],
            'unread_count' => $unreadCount,
        ]);
    }

    public function show(Request $request, string $id): Response
    {
        $user = $request->user();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $row = $this->repo->find($id);

        if (!$row) {
            return $this->json(['error' => 'Not Found'], 404);
        }

        if ((int) ($row['user_id'] ?? 0) !== (int) $user->id) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        return $this->json(['success' => true, 'data' => $row]);
    }

    public function markAsRead(Request $request, string $id): Response
    {
        $user = $request->user();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $row = $this->repo->find($id);

        if (!$row) {
            return $this->json(['error' => 'Not Found'], 404);
        }

        if ((int) ($row['user_id'] ?? 0) !== (int) $user->id) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        $this->repo->markAsRead($id);

        return $this->json(['success' => true, 'id' => $id]);
    }

    public function markAllAsRead(Request $request): Response
    {
        $user = $request->user();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $items = $this->repo->forUser((int) $user->id);
        $count = 0;

        foreach ($items as $row) {
            if (empty($row['read_at']) && $this->repo->markAsRead($row['id'])) {
                $count++;
            }
        }

        return $this->json(['success' => true, 'marked' => $count]);
    }
}