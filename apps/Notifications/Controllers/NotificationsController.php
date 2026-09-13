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

        $items = $this->repo->forUser((int) $user->id, [
            'unread' => (bool) $request->query('unread', false),
        ]);

        return $this->json(['success' => true, 'data' => $items]);
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