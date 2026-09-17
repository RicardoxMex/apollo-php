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

        // Anti double-registration: pending/accepted with the same participant.
        // Una fila rejected/cancelled del mismo participante NO bloquea: se
        // reutiliza (el UNIQUE (tournament_id, team_id/player_id) impide insertar otra).
        $existing = $this->findExistingRegistration($pdo, $tournamentId, $teamId, $playerId);
        $alreadyRegistered = $existing !== null && in_array($existing['status'], ['pending', 'accepted'], true);

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM tournament_participants WHERE tournament_id = ?');
        $stmt->execute([$tournamentId]);
        $isFull = ((int) $stmt->fetchColumn()) >= (int) $tournament['max_participants'];

        $check = TournamentRules::validateRegistration($tournament, $teamId, $playerId, $alreadyRegistered, $isFull, (int) $tournament['organizer_id'] === $actorId);
        if (!$check['ok']) {
            throw new \RuntimeException($check['reason'], 409);
        }

        if ($existing !== null) {
            // Reinscripción: se reutiliza la fila previa (rejected/cancelled)
            // en lugar de insertar (evita el 500 por UNIQUE). Vuelve a pending
            // y se limpia la decisión anterior.
            $pdo->prepare(
                'UPDATE tournament_registrations
                 SET applicant_id = ?, status = ?, message = NULL, decided_by = NULL, decided_at = NULL, updated_at = ?
                 WHERE id = ?'
            )->execute([$actorId, 'pending', date('Y-m-d H:i:s'), (int) $existing['id']]);
            $registrationId = (int) $existing['id'];
        } else {
            $pdo->prepare(
                "INSERT INTO tournament_registrations (tournament_id, team_id, player_id, applicant_id, status, message, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 'pending', ?, ?, ?)"
            )->execute([$tournamentId, $teamId, $playerId, $actorId, $message, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
            $registrationId = (int) $pdo->lastInsertId();
        }

        $this->audit->record($actorId, 'registration', $registrationId, 'inscripcion:solicitar', null, ['tournament_id' => $tournamentId, 'team_id' => $teamId, 'player_id' => $playerId, 'reutilizada' => $existing !== null], $request);

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

            // Email al organizador (best-effort; el mailer nunca rompe el flujo).
            $this->emailToUser(
                (int) $tournament['organizer_id'],
                'registration_request',
                [
                    'team' => $result['display_name'],
                    'tournament' => $tournament['title'],
                    'link' => $this->panelUrl($tournament),
                ],
            );
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
        $now = date('Y-m-d H:i:s');

        if ($action === 'accepted') {
            // Aceptar es atómico: cupo + participante + estado de la solicitud.
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM tournament_participants WHERE tournament_id = ?');
                $stmt->execute([$tournamentId]);
                $occupied = (int) $stmt->fetchColumn();
                if ($occupied >= (int) $tournament['max_participants']) {
                    throw new \RuntimeException('El torneo alcanzó su cupo máximo', 409);
                }

                $pdo->prepare(
                    'INSERT INTO tournament_participants (tournament_id, registration_id, team_id, player_id, seed, created_at)
                     VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([$tournamentId, $registrationId, $registration['team_id'], $registration['player_id'], $occupied + 1, $now]);

                $pdo->prepare('UPDATE tournament_registrations SET status = ?, decided_by = ?, decided_at = ?, updated_at = ? WHERE id = ?')
                    ->execute([$newStatus, $actorId, $now, $now, $registrationId]);

                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        } else {
            $pdo->prepare('UPDATE tournament_registrations SET status = ?, decided_by = ?, decided_at = ?, updated_at = ? WHERE id = ?')
                ->execute([$newStatus, $actorId, $now, $now, $registrationId]);
        }

        $this->audit->record($actorId, 'registration', $registrationId, "inscripcion:{$action}", $registration, null, $request);

        // Notificaciones/emails SIEMPRE después del commit (best-effort).

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

            // Email al solicitante (best-effort; el mailer nunca rompe el flujo).
            $this->emailToUser(
                (int) $registration['applicant_id'],
                'registration_decision',
                [
                    'tournament' => $tournament['title'],
                    'status' => $aceptada ? 'aceptada' : 'rechazada',
                    'reason' => '',
                    'link' => $this->panelUrl($tournament),
                ],
            );
        }

        return $this->show($registrationId);
    }

    /**
     * Cancels a request:
     *  - organizer: pending or accepted (accepted releases the participant slot);
     *  - applicant (pending only);
     *  - linked player (players.user_id = actor, via registration.player_id): pending only.
     */
    public function cancel(int $actorId, int $tournamentId, int $registrationId, ?Request $request = null): array
    {
        $tournament = $this->findTournament($tournamentId);
        $registration = $this->findRegistration($registrationId, $tournamentId);

        $pdo = $this->pdo();
        $isOrganizer = (int) $tournament['organizer_id'] === $actorId;
        $isApplicant = (int) $registration['applicant_id'] === $actorId;
        $isLinkedPlayer = !$isApplicant
            && $this->isLinkedPlayer($pdo, $registration['player_id'] !== null ? (int) $registration['player_id'] : null, $actorId);
        if (!$isOrganizer && !$isApplicant && !$isLinkedPlayer) {
            throw new \RuntimeException('No puedes cancelar esta solicitud', 403);
        }

        $status = $registration['status'];
        if ($status === 'accepted') {
            // Una inscripción aceptada solo la cancela el organizador; el
            // solicitante no puede deshacerla (403).
            if (!$isOrganizer) {
                throw new \RuntimeException('Una inscripción aceptada solo puede cancelarla el organizador', 403);
            }
        } elseif ($status !== 'pending') {
            throw new \RuntimeException("La solicitud ya fue decidida ({$status})", 409);
        }

        $pdo->beginTransaction();
        try {
            if ($status === 'accepted') {
                // Libera cupo: elimina el participante resuelto de la solicitud.
                // Antes de borrarlo se limpian SUS partidos: si no, la FK
                // (ON DELETE SET NULL) dejaba cruces huérfanos («Bye vs Bye»).
                $stmt = $pdo->prepare('SELECT id FROM tournament_participants WHERE registration_id = ? AND tournament_id = ?');
                $stmt->execute([$registrationId, $tournamentId]);
                $participantId = (int) $stmt->fetchColumn();
                if ($participantId > 0) {
                    $this->purgeParticipantSchedule($pdo, $tournamentId, $participantId);
                }
                $pdo->prepare('DELETE FROM tournament_participants WHERE registration_id = ? AND tournament_id = ?')
                    ->execute([$registrationId, $tournamentId]);
            }
            $pdo->prepare("UPDATE tournament_registrations SET status = 'cancelled', updated_at = ? WHERE id = ?")
                ->execute([date('Y-m-d H:i:s'), $registrationId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        // Clasificación (M2): sin participante cambian los partidos → invalida caché.
        \Apps\Tournaments\Services\StandingsService::invalidate($tournamentId);

        $this->audit->record($actorId, 'registration', $registrationId, 'inscripcion:cancelar', $registration, null, $request);

        // Cancelación de una aceptada por el organizador: se avisa al
        // solicitante (in-app + email best-effort).
        if ($status === 'accepted' && (int) $registration['applicant_id'] !== $actorId) {
            $this->notify((int) $registration['applicant_id'], 'registro.cancelado', [
                'title' => 'Inscripción cancelada',
                'message' => "Tu inscripción en «{$tournament['title']}» fue cancelada por el organizador",
                'data' => [
                    'tournament_id' => (int) $tournamentId,
                    'registration_id' => $registrationId,
                    'slug' => $tournament['slug'] ?? null,
                ],
            ]);

            $this->emailToUser(
                (int) $registration['applicant_id'],
                'registration_decision',
                [
                    'tournament' => $tournament['title'],
                    'status' => 'cancelada',
                    'reason' => ' por el organizador',
                    'link' => $this->panelUrl($tournament),
                ],
            );
        }

        return $this->show($registrationId);
    }

    /**
     * Saca del calendario a un participante que abandona el torneo (su
     * inscripción aceptada se cancela). Sin esto, la FK
     * `matches.participant_a_id/b_id ON DELETE SET NULL` dejaba partidos
     * huérfanos (los dos lados NULL) que la UI mostraba como «Bye vs Bye».
     *
     * - Partido sin resultado (sin marcador ni ganador): se elimina con sus
     *   marcadores/stats de jugador.
     * - Partido con resultado: se conserva como historial, marcado `cancelled`
     *   y sin ganador.
     * - Cupos del sorteo (bracket): el cruce vuelve a quedar libre.
     * No toca partidos de otros participantes.
     */
    private function purgeParticipantSchedule(PDO $pdo, int $tournamentId, int $participantId): void
    {
        $stmt = $pdo->prepare(
            'SELECT m.id, m.winner_participant_id,
                    (SELECT COUNT(*) FROM match_scores ms WHERE ms.match_id = m.id) AS marcadores
             FROM matches m
             WHERE m.tournament_id = ? AND (m.participant_a_id = ? OR m.participant_b_id = ?)'
        );
        $stmt->execute([$tournamentId, $participantId, $participantId]);

        $now = date('Y-m-d H:i:s');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $matchId = (int) $m['id'];
            $conResultado = (int) $m['marcadores'] > 0 || $m['winner_participant_id'] !== null;

            if ($conResultado) {
                $pdo->prepare("UPDATE matches SET status = 'cancelled', winner_participant_id = NULL, updated_at = ? WHERE id = ?")
                    ->execute([$now, $matchId]);
                continue;
            }

            $pdo->prepare('DELETE FROM match_player_stats WHERE match_id = ?')->execute([$matchId]);
            $pdo->prepare('DELETE FROM match_scores WHERE match_id = ?')->execute([$matchId]);
            $pdo->prepare('DELETE FROM matches WHERE id = ?')->execute([$matchId]);
        }

        // Cupos del sorteo (bracket): al salir el participante, el cruce queda libre.
        $pdo->prepare('UPDATE draw_matches SET participant_a_id = NULL WHERE participant_a_id = ?')->execute([$participantId]);
        $pdo->prepare('UPDATE draw_matches SET participant_b_id = NULL WHERE participant_b_id = ?')->execute([$participantId]);
    }

    /**
     * Tournament requests (organizer only), with resolved names and payments.
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
        $registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Pagos por inscripción (M4): el organizador ve el estado del pago
        // junto a cada solicitud.
        if ($registrations !== []) {
            $ids = array_column($registrations, 'id');
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->pdo()->prepare(
                "SELECT id, registration_id, amount, currency, method, reference, status, paid_at, notes
                 FROM payments WHERE registration_id IN ({$in}) ORDER BY created_at DESC"
            );
            $stmt->execute($ids);
            $byRegistration = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $payment) {
                $payment['amount'] = (float) $payment['amount'];
                $byRegistration[(int) $payment['registration_id']][] = $payment;
            }
            foreach ($registrations as &$r) {
                $r['payments'] = $byRegistration[(int) $r['id']] ?? [];
            }
            unset($r);
        }

        return $registrations;
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

    /**
     * Email transaccional al organizador o solicitante (EMAIL-05). Best-effort:
     * el Mailer nunca lanza al caller; aquí además se aíslan fallos de
     * resolución de destinatario. El nombre se resuelve del usuario.
     */
    private function emailToUser(int $userId, string $template, array $vars): void
    {
        try {
            $stmt = $this->pdo()->prepare('SELECT email, username, first_name, last_name FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user || empty($user['email'])) {
                return;
            }

            $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
            $vars['name'] = $name !== '' ? $name : ($user['username'] ?? '');

            mailer()->sendTemplate($template, $user['email'], $vars);
        } catch (\Throwable $e) {
            error_log("Email transaccional fallido ({$template}): " . $e->getMessage());
        }
    }

    /** URL del panel del organizador (o detalle público del torneo). */
    private function panelUrl(array $tournament): string
    {
        $frontend = rtrim((string) config('mail.frontend_url', 'http://localhost:3000'), '/');
        $slug = $tournament['slug'] ?? null;
        return $slug ? "{$frontend}/mis-torneos/{$slug}" : "{$frontend}/dashboard";
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

    /**
     * True si el actor es el jugador vinculado a la inscripción
     * (players.user_id = actor, vía registration.player_id).
     */
    private function isLinkedPlayer(PDO $pdo, ?int $playerId, int $actorId): bool
    {
        if ($playerId === null) {
            return false;
        }

        $stmt = $pdo->prepare('SELECT 1 FROM players WHERE id = ? AND user_id = ?');
        $stmt->execute([$playerId, $actorId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Fila existente del participante en el torneo (cualquier estado). El
     * UNIQUE (tournament_id, team_id/player_id) garantiza como máximo una.
     */
    private function findExistingRegistration(PDO $pdo, int $tournamentId, ?int $teamId, ?int $playerId): ?array
    {
        if ($teamId !== null) {
            $stmt = $pdo->prepare('SELECT * FROM tournament_registrations WHERE tournament_id = ? AND team_id = ?');
            $stmt->execute([$tournamentId, $teamId]);
        } elseif ($playerId !== null) {
            $stmt = $pdo->prepare('SELECT * FROM tournament_registrations WHERE tournament_id = ? AND player_id = ?');
            $stmt->execute([$tournamentId, $playerId]);
        } else {
            return null;
        }

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}