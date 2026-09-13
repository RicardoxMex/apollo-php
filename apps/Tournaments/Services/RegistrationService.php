<?php

namespace Apps\Tournaments\Services;

use Apollo\Core\Database\Connection\DatabaseManager;
use Apollo\Core\Http\Request;
use PDO;

class RegistrationService
{
    public function __construct(
        private TournamentService $tournaments,
        private AuditLogService $audit,
    ) {
    }

    private function pdo(): PDO
    {
        return DatabaseManager::getConnection();
    }

    /**
     * Registration request (public profile). Applies pure rules + capacity.
     */
    public function apply(int $actorId, int $tournamentId, array $data, ?Request $request = null): array
    {
        $tournament = $this->findTournament($tournamentId);
        $teamId = !empty($data['team_id']) ? (int) $data['team_id'] : null;
        $playerId = !empty($data['player_id']) ? (int) $data['player_id'] : null;
        $message = $data['message'] ?? null;

        $pdo = $this->pdo();

        // Anti double-registration: pending/accepted with the same participant
        $alreadyRegistered = false;
        if ($teamId !== null) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM tournament_registrations WHERE tournament_id = ? AND team_id = ? AND status IN ('pending','accepted')");
            $stmt->execute([$tournamentId, $teamId]);
            $alreadyRegistered = ((int) $stmt->fetchColumn()) > 0;
        }
        if ($playerId !== null) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM tournament_registrations WHERE tournament_id = ? AND player_id = ? AND status IN ('pending','accepted')");
            $stmt->execute([$tournamentId, $playerId]);
            $alreadyRegistered = $alreadyRegistered || ((int) $stmt->fetchColumn()) > 0;
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM tournament_participants WHERE tournament_id = ?');
        $stmt->execute([$tournamentId]);
        $isFull = ((int) $stmt->fetchColumn()) >= (int) $tournament['max_participants'];

        $check = TournamentRules::validateRegistration($tournament, $teamId, $playerId, $alreadyRegistered, $isFull, (int) $tournament['organizer_id'] === $actorId);
        if (!$check['ok']) {
            throw new \RuntimeException($check['reason'], 409);
        }

        $pdo->prepare(
            "INSERT INTO tournament_registrations (tournament_id, team_id, player_id, applicant_id, status, message, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'pending', ?, ?, ?)"
        )->execute([$tournamentId, $teamId, $playerId, $actorId, $message, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);

        $registrationId = (int) $pdo->lastInsertId();
        $this->audit->record($actorId, 'registration', $registrationId, 'inscripcion:solicitar', null, ['tournament_id' => $tournamentId, 'team_id' => $teamId, 'player_id' => $playerId], $request);

        $result = $this->show($registrationId);

        // Notifica al organizador (no a sí mismo).
        if ((int) $tournament['organizer_id'] !== $actorId) {
            $this->notify((int) $tournament['organizer_id'], 'registro.solicitado', [
                'title' => 'Nueva solicitud de inscripción',
                'message' => "{$result['display_name']} quiere inscribirse en «{$tournament['title']}»",
                'data' => [
                    'tournament_id' => (int) $tournamentId,
                    'registration_id' => $registrationId,
                    'slug' => $tournament['slug'] ?? null,
                ],
            ]);
        }

        return $result;
    }

    /**
     * Moderation: accept → creates the participant (respects capacity); reject → status.
     */
    public function decide(int $actorId, int $tournamentId, int $registrationId, array $data, ?Request $request = null): array
    {
        $tournament = $this->findTournament($tournamentId);
        if (!$this->tournaments->isOrganizer($actorId, $tournament)) {
            throw new \RuntimeException('No eres el organizador de este torneo', 403);
        }

        $registration = $this->findRegistration($registrationId, $tournamentId);
        $action = $data['action'] ?? null;

        $check = TournamentRules::canDecide($registration['status'], $action);
        if (!$check['ok']) {
            throw new \RuntimeException($check['reason'], 409);
        }

        $pdo = $this->pdo();
        $newStatus = $action === 'accepted' ? 'accepted' : 'rejected';

        if ($action === 'accepted') {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM tournament_participants WHERE tournament_id = ?');
            $stmt->execute([$tournamentId]);
            $occupied = (int) $stmt->fetchColumn();
            if ($occupied >= (int) $tournament['max_participants']) {
                throw new \RuntimeException('El torneo alcanzó su cupo máximo', 409);
            }

            $pdo->prepare(
                'INSERT INTO tournament_participants (tournament_id, registration_id, team_id, player_id, seed, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$tournamentId, $registrationId, $registration['team_id'], $registration['player_id'], $occupied + 1, date('Y-m-d H:i:s')]);
        }

        $pdo->prepare('UPDATE tournament_registrations SET status = ?, decided_by = ?, decided_at = ?, updated_at = ? WHERE id = ?')
            ->execute([$newStatus, $actorId, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $registrationId]);

        $this->audit->record($actorId, 'registration', $registrationId, "inscripcion:{$action}", $registration, null, $request);

        // Notifica al solicitante el resultado de la moderación.
        if ((int) $registration['applicant_id'] !== $actorId) {
            $aceptada = $action === 'accepted';
            $this->notify((int) $registration['applicant_id'], 'registro.decidido', [
                'title' => $aceptada ? 'Inscripción aceptada' : 'Inscripción rechazada',
                'message' => $aceptada
                    ? "Tu inscripción en «{$tournament['title']}» fue aceptada"
                    : "Tu inscripción en «{$tournament['title']}» fue rechazada",
                'data' => [
                    'tournament_id' => (int) $tournamentId,
                    'registration_id' => $registrationId,
                    'accepted' => $aceptada,
                    'slug' => $tournament['slug'] ?? null,
                ],
            ]);
        }

        return $this->show($registrationId);
    }

    /**
     * The applicant cancels their own pending request.
     */
    public function cancel(int $actorId, int $tournamentId, int $registrationId, ?Request $request = null): array
    {
        $tournament = $this->findTournament($tournamentId);
        $registration = $this->findRegistration($registrationId, $tournamentId);

        $isOrganizer = (int) $tournament['organizer_id'] === $actorId;
        $isApplicant = (int) $registration['applicant_id'] === $actorId;
        if (!$isOrganizer && !$isApplicant) {
            throw new \RuntimeException('No puedes cancelar esta solicitud', 403);
        }
        if ($registration['status'] !== 'pending') {
            throw new \RuntimeException("La solicitud ya fue decidida ({$registration['status']})", 409);
        }

        $this->pdo()->prepare("UPDATE tournament_registrations SET status = 'cancelled', updated_at = ? WHERE id = ?")
            ->execute([date('Y-m-d H:i:s'), $registrationId]);

        $this->audit->record($actorId, 'registration', $registrationId, 'inscripcion:cancelar', $registration, null, $request);
        return $this->show($registrationId);
    }

    /**
     * Tournament requests (organizer only), with resolved names.
     */
    public function list(int $tournamentId, int $actorId, ?string $status = null): array
    {
        $tournament = $this->findTournament($tournamentId);
        if (!$this->tournaments->isOrganizer($actorId, $tournament)) {
            throw new \RuntimeException('No eres el organizador de este torneo', 403);
        }

        $sql = 'SELECT r.*, COALESCE(t.name, p.name) AS display_name
                FROM tournament_registrations r
                LEFT JOIN teams t ON t.id = r.team_id
                LEFT JOIN players p ON p.id = r.player_id
                WHERE r.tournament_id = ?';
        $params = [$tournamentId];
        if ($status !== null && in_array($status, ['pending', 'accepted', 'rejected', 'cancelled'], true)) {
            $sql .= ' AND r.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY r.created_at ASC';

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function show(int $registrationId): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT r.*, COALESCE(t.name, p.name) AS display_name
             FROM tournament_registrations r
             LEFT JOIN teams t ON t.id = r.team_id
             LEFT JOIN players p ON p.id = r.player_id
             WHERE r.id = ?'
        );
        $stmt->execute([$registrationId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function findTournament(int $tournamentId): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM tournaments WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$tournamentId]);
        $tournament = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$tournament) {
            throw new \RuntimeException('Torneo no encontrado', 404);
        }
        return $tournament;
    }

    /**
     * Persiste una notificación vía el core (guía websockets §13). Nunca rompe
     * el flujo principal: un fallo de notificación solo se loguea.
     */
    private function notify(int $userId, string $type, array $payload): void
    {
        try {
            $service = new \Apollo\Core\Realtime\Notifications\NotificationService(
                new \Apollo\Core\Realtime\Notifications\MySqlNotificationRepository()
            );
            $service->sendToUser($userId, $type, $payload);
        } catch (\Throwable $e) {
            error_log("Notificación fallida ({$type}): " . $e->getMessage());
        }
    }

    private function findRegistration(int $registrationId, int $tournamentId): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM tournament_registrations WHERE id = ? AND tournament_id = ?');
        $stmt->execute([$registrationId, $tournamentId]);
        $registration = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$registration) {
            throw new \RuntimeException('Solicitud no encontrada', 404);
        }
        return $registration;
    }
}