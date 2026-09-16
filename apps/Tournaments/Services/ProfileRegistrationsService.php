<?php

namespace Apps\Tournaments\Services;

use Apollo\Core\Database\Connection\DatabaseManager;
use PDO;

/**
 * Inscripciones del participante (PORTAL-01, REQ-01 / D-F1-1):
 * - Actor-scoped: solo las filas donde el usuario es el solicitante
 *   (applicant_id) o el jugador vinculado (players.user_id).
 * - Torneo, participante (equipo/jugador), estado/fechas y resumen de pago
 *   agregado por registration_id (sin N+1: consulta base + agregado agrupado).
 * - Privacidad: nunca expone notes/recorded_by/decided_by ni datos ajenos.
 */
class ProfileRegistrationsService
{
    /**
     * Lista las inscripciones del usuario, más recientes primero.
     */
    public function list(int $userId): array
    {
        $pdo = DatabaseManager::getConnection();

        $stmt = $pdo->prepare(
            'SELECT r.id, r.status, r.created_at, r.decided_at,
                    t.id AS tournament_id, t.slug, t.title, t.status AS tournament_status,
                    t.registration_fee, t.currency, t.start_date,
                    r.team_id, tm.name AS team_name,
                    r.player_id, pl.name AS player_name
             FROM tournament_registrations r
             JOIN tournaments t ON t.id = r.tournament_id
             LEFT JOIN teams tm ON tm.id = r.team_id
             LEFT JOIN players pl ON pl.id = r.player_id
             WHERE (r.applicant_id = :applicant
                    OR EXISTS(SELECT 1 FROM players linked
                              WHERE linked.id = r.player_id AND linked.user_id = :linked_player))
               AND t.deleted_at IS NULL
             ORDER BY r.created_at DESC, r.id DESC'
        );
        $stmt->execute(['applicant' => $userId, 'linked_player' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $paidTotals = $this->paidTotalsPorInscripcion($pdo, array_column($rows, 'id'));

        return array_map(
            fn (array $row): array => $this->map($row, $paidTotals[(int) $row['id']] ?? 0.0),
            $rows,
        );
    }

    /**
     * Suma de pagos con status='paid' por inscripción, en una sola consulta
     * (mismo patrón que TournamentService::countAceptadosPorTorneo).
     *
     * @return array<int, float> [registration_id => paid_total]
     */
    private function paidTotalsPorInscripcion(PDO $pdo, array $registrationIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $registrationIds),
            static fn (int $id): bool => $id > 0,
        )));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT registration_id, SUM(amount) AS paid_total
             FROM payments
             WHERE status = 'paid' AND registration_id IN ({$placeholders})
             GROUP BY registration_id"
        );
        $stmt->execute($ids);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['registration_id']] = (float) $row['paid_total'];
        }
        return $out;
    }

    private function map(array $row, float $paidTotal): array
    {
        $fee = (float) $row['registration_fee'];
        $paidTotal = round($paidTotal, 2);
        $amountDue = max(0.0, round($fee - $paidTotal, 2));

        if ($fee <= 0) {
            $paymentStatus = 'none';
        } elseif ($paidTotal >= $fee) {
            $paymentStatus = 'paid';
        } else {
            $paymentStatus = 'pending';
        }

        return [
            'id' => (int) $row['id'],
            'tournament' => [
                'id' => (int) $row['tournament_id'],
                'slug' => $row['slug'],
                'title' => $row['title'],
                'status' => $row['tournament_status'],
                'registration_fee' => $fee,
                'currency' => $row['currency'],
                'start_date' => $row['start_date'],
            ],
            'participant' => [
                'team_id' => $row['team_id'] !== null ? (int) $row['team_id'] : null,
                'team_name' => $row['team_name'],
                'player_id' => $row['player_id'] !== null ? (int) $row['player_id'] : null,
                'player_name' => $row['player_name'],
            ],
            'status' => $row['status'],
            'created_at' => $row['created_at'],
            'decided_at' => $row['decided_at'],
            'payment' => [
                'status' => $paymentStatus,
                'paid_total' => $paidTotal,
                'amount_due' => $amountDue,
                'currency' => $row['currency'],
            ],
        ];
    }
}
