<?php

namespace Apps\Tournaments\Services;

use Apollo\Core\Database\Connection\DatabaseManager;
use Apollo\Core\Http\Request;
use PDO;

/**
 * Pagos manuales de inscripción (M4, D5 revisado): sin proveedor; el
 * organizador registra el pago (efectivo, transferencia, tarjeta, otro) contra
 * una inscripción (o participante directo). El pago NO bloquea la moderación.
 * Toda escritura queda en audit_logs (lógica financiera).
 */
class PaymentService
{
    public const METHODS = ['efectivo', 'transferencia', 'tarjeta', 'otro'];

    public function __construct(
        private AuditLogService $audit,
    ) {
    }

    private function pdo(): PDO
    {
        return DatabaseManager::getConnection();
    }

    /**
     * Registra un pago manual (organizador). El monto sugerido es el fee del
     * torneo; se valida monto > 0 y método permitido.
     */
    public function register(int $actorId, int $tournamentId, array $data, ?Request $request = null): array
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

        $amount = (float) ($data['amount'] ?? $tournament['registration_fee'] ?? 0);
        if ($amount <= 0) {
            throw new \InvalidArgumentException('El monto debe ser mayor a 0');
        }
        $method = (string) ($data['method'] ?? '');
        if (!in_array($method, self::METHODS, true)) {
            throw new \InvalidArgumentException('Método de pago inválido: ' . implode(', ', self::METHODS));
        }

        $registrationId = !empty($data['registration_id']) ? (int) $data['registration_id'] : null;
        if ($registrationId !== null) {
            $stmt = $pdo->prepare('SELECT id FROM tournament_registrations WHERE id = ? AND tournament_id = ?');
            $stmt->execute([$registrationId, $tournamentId]);
            if (!$stmt->fetchColumn()) {
                throw new \InvalidArgumentException('La inscripción no pertenece a este torneo');
            }
        }

        $now = date('Y-m-d H:i:s');
        $status = $data['status'] ?? 'paid';
        if (!in_array($status, ['pending', 'paid', 'refunded'], true)) {
            $status = 'paid';
        }

        $pdo->prepare(
            'INSERT INTO payments (tournament_id, registration_id, participant_id, amount, currency, method, reference, status, paid_at, recorded_by, notes, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $tournamentId,
            $registrationId,
            !empty($data['participant_id']) ? (int) $data['participant_id'] : null,
            $amount,
            strtoupper((string) ($data['currency'] ?? $tournament['currency'] ?? 'USD')),
            $method,
            $data['reference'] ?? null,
            $status,
            $status === 'paid' ? ($data['paid_at'] ?? $now) : null,
            $actorId,
            $data['notes'] ?? null,
            $now,
            $now,
        ]);
        $paymentId = (int) $pdo->lastInsertId();

        $this->audit->record($actorId, 'payment', $paymentId, 'pago:registrar', null, [
            'tournament_id' => $tournamentId,
            'registration_id' => $registrationId,
            'amount' => $amount,
            'method' => $method,
            'reference' => $data['reference'] ?? null,
        ], $request);

        return $this->show($paymentId);
    }

    /**
     * Listado de pagos del torneo con la inscripción resuelta (organizador).
     */
    public function list(int $actorId, int $tournamentId): array
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

        $stmt = $pdo->prepare(
            'SELECT p.*, COALESCE(t.name, pl.name) AS registration_name,
                    u.username AS recorded_by_username
             FROM payments p
             LEFT JOIN tournament_registrations r ON r.id = p.registration_id
             LEFT JOIN teams t ON t.id = r.team_id
             LEFT JOIN players pl ON pl.id = r.player_id
             LEFT JOIN users u ON u.id = p.recorded_by
             WHERE p.tournament_id = ?
             ORDER BY p.created_at DESC'
        );
        $stmt->execute([$tournamentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Elimina un registro de pago (organizador; registros erróneos).
     */
    public function delete(int $actorId, int $tournamentId, int $paymentId, ?Request $request = null): bool
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

        $stmt = $pdo->prepare('SELECT * FROM payments WHERE id = ? AND tournament_id = ?');
        $stmt->execute([$paymentId, $tournamentId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$payment) {
            throw new \RuntimeException('Pago no encontrado', 404);
        }

        $pdo->prepare('DELETE FROM payments WHERE id = ?')->execute([$paymentId]);
        $this->audit->record($actorId, 'payment', $paymentId, 'pago:eliminar', $payment, null, $request);
        return true;
    }

    private function show(int $paymentId): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM payments WHERE id = ?');
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$payment) {
            throw new \RuntimeException('Pago no encontrado', 404);
        }
        $payment['amount'] = (float) $payment['amount'];
        return $payment;
    }
}