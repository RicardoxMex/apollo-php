<?php

namespace Apps\Tournaments\Services;

use Apollo\Core\Database\Connection\DatabaseManager;
use Apollo\Core\Http\Request;
use PDO;

/**
 * Tablón de anuncios del torneo (M6, D7):
 * - crear/eliminar solo el organizador; lectura pública.
 * - Al publicar: notificación in-app a los participantes aceptados
 *   (best-effort, reusa NotificationService); con $notifyEmail también email
 *   (plantilla 'announcement', best-effort).
 */
class AnnouncementService
{
    public function __construct(
        private AuditLogService $audit,
    ) {
    }

    private function pdo(): PDO
    {
        return DatabaseManager::getConnection();
    }

    public function create(int $actorId, int $tournamentId, array $data, bool $notifyEmail = false, ?Request $request = null): array
    {
        $pdo = $this->pdo();

        $stmt = $pdo->prepare('SELECT * FROM tournaments WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$tournamentId]);
        $tournament = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$tournament) {
            throw new \RuntimeException('Torneo no encontrado', 404);
        }
        if ((int) $tournament['organizer_id'] !== $actorId) {
            throw new \RuntimeException('No eres el organizador de este torneo', 403);
        }

        $title = trim((string) ($data['title'] ?? ''));
        $body = trim((string) ($data['body'] ?? ''));
        if ($title === '') {
            throw new \InvalidArgumentException('El título del anuncio es obligatorio');
        }
        if ($body === '') {
            throw new \InvalidArgumentException('El contenido del anuncio es obligatorio');
        }

        $now = date('Y-m-d H:i:s');
        $pdo->prepare(
            'INSERT INTO tournament_announcements (tournament_id, author_id, title, body, pinned, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $tournamentId,
            $actorId,
            $title,
            $body,
            !empty($data['pinned']) ? 1 : 0,
            $now,
            $now,
        ]);
        $announcementId = (int) $pdo->lastInsertId();

        $this->audit->record($actorId, 'announcement', $announcementId, 'anuncio:crear', null, [
            'tournament_id' => $tournamentId,
            'title' => $title,
            'pinned' => !empty($data['pinned']) ? 1 : 0,
            'notify_email' => $notifyEmail,
        ], $request);

        // Notificación in-app a los participantes aceptados (best-effort).
        $this->notifyParticipants($tournamentId, $tournament, $title);
        // Email opcional a los participantes aceptados (best-effort).
        if ($notifyEmail) {
            $this->emailParticipants($tournamentId, $tournament, $title, $body);
        }

        return $this->show($announcementId);
    }

    /** Lectura pública: pinned primero, luego por fecha desc. */
    public function listPublic(int $tournamentId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT a.*, u.username AS author_name
             FROM tournament_announcements a
             LEFT JOIN users u ON u.id = a.author_id
             WHERE a.tournament_id = ?
             ORDER BY a.pinned DESC, a.created_at DESC, a.id DESC'
        );
        $stmt->execute([$tournamentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function delete(int $actorId, int $tournamentId, int $announcementId, ?Request $request = null): bool
    {
        $pdo = $this->pdo();

        $stmt = $pdo->prepare('SELECT * FROM tournaments WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$tournamentId]);
        $tournament = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$tournament) {
            throw new \RuntimeException('Torneo no encontrado', 404);
        }
        if ((int) $tournament['organizer_id'] !== $actorId) {
            throw new \RuntimeException('No eres el organizador de este torneo', 403);
        }

        $stmt = $pdo->prepare('SELECT * FROM tournament_announcements WHERE id = ? AND tournament_id = ?');
        $stmt->execute([$announcementId, $tournamentId]);
        $announcement = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$announcement) {
            throw new \RuntimeException('Anuncio no encontrado', 404);
        }

        $pdo->prepare('DELETE FROM tournament_announcements WHERE id = ?')->execute([$announcementId]);
        $this->audit->record($actorId, 'announcement', $announcementId, 'anuncio:eliminar', $announcement, null, $request);
        return true;
    }

    private function show(int $announcementId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT a.*, u.username AS author_name
             FROM tournament_announcements a
             LEFT JOIN users u ON u.id = a.author_id
             WHERE a.id = ?'
        );
        $stmt->execute([$announcementId]);
        $announcement = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$announcement) {
            throw new \RuntimeException('Anuncio no encontrado', 404);
        }
        return $announcement;
    }

    /** Participantes aceptados (applicant_id) del torneo. */
    private function participantUserIds(int $tournamentId): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT DISTINCT applicant_id FROM tournament_registrations
             WHERE tournament_id = ? AND status = 'accepted' AND applicant_id IS NOT NULL"
        );
        $stmt->execute([$tournamentId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0));
    }

    private function notifyParticipants(int $tournamentId, array $tournament, string $title): void
    {
        try {
            $service = new \Apollo\Core\Realtime\Notifications\NotificationService(
                new \Apollo\Core\Realtime\Notifications\MySqlNotificationRepository()
            );
            foreach ($this->participantUserIds($tournamentId) as $userId) {
                $service->sendToUser($userId, 'anuncio.nuevo', [
                    'title' => 'Nuevo anuncio',
                    'message' => "«{$tournament['title']}»: {$title}",
                    'data' => [
                        'tournament_id' => $tournamentId,
                        'slug' => $tournament['slug'] ?? null,
                    ],
                ]);
            }
        } catch (\Throwable $e) {
            error_log('Notificación de anuncio fallida: ' . $e->getMessage());
        }
    }

    private function emailParticipants(int $tournamentId, array $tournament, string $title, string $body): void
    {
        try {
            $stmt = $this->pdo()->prepare(
                'SELECT DISTINCT u.email, u.username, u.first_name, u.last_name
                 FROM tournament_registrations r
                 JOIN users u ON u.id = r.applicant_id
                 WHERE r.tournament_id = ? AND r.status = \'accepted\' AND u.email IS NOT NULL'
            );
            $stmt->execute([$tournamentId]);
            $frontend = rtrim((string) config('mail.frontend_url', 'http://localhost:3000'), '/');
            $link = "{$frontend}/torneo/" . ($tournament['slug'] ?? $tournamentId);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $user) {
                $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                mailer()->sendTemplate(
                    'announcement',
                    $user['email'],
                    [
                        'name' => $name !== '' ? $name : ($user['username'] ?? ''),
                        'tournament' => $tournament['title'],
                        'title' => $title,
                        'body' => $body,
                        'link' => $link,
                    ]
                );
            }
        } catch (\Throwable $e) {
            error_log('Email de anuncio fallido: ' . $e->getMessage());
        }
    }
}