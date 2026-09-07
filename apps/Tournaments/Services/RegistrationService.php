<?php

namespace Apps\Tournaments\Services;

use Apollo\Core\Database\Connection\DatabaseManager;
use Apollo\Core\Http\Request;
use PDO;

class RegistrationService
{
    public function __construct(
        private TournamentService $torneos,
        private AuditLogService $audit,
    ) {
    }

    private function pdo(): PDO
    {
        return DatabaseManager::getConnection();
    }

    /**
     * Solicitud de inscripción (perfil público). Aplica reglas puras + cupo.
     */
    public function aplicar(int $actorId, int $torneoId, array $data, ?Request $request = null): array
    {
        $torneo = $this->buscarTorneo($torneoId);
        $teamId = !empty($data['team_id']) ? (int) $data['team_id'] : null;
        $playerId = !empty($data['player_id']) ? (int) $data['player_id'] : null;
        $mensaje = $data['message'] ?? null;

        $pdo = $this->pdo();

        // Anti doble-inscripción: pendiente/aceptada con el mismo participante
        $yaInscrito = false;
        if ($teamId !== null) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM tournament_registrations WHERE tournament_id = ? AND team_id = ? AND status IN ('pending','accepted')");
            $stmt->execute([$torneoId, $teamId]);
            $yaInscrito = ((int) $stmt->fetchColumn()) > 0;
        }
        if ($playerId !== null) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM tournament_registrations WHERE tournament_id = ? AND player_id = ? AND status IN ('pending','accepted')");
            $stmt->execute([$torneoId, $playerId]);
            $yaInscrito = $yaInscrito || ((int) $stmt->fetchColumn()) > 0;
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM tournament_participants WHERE tournament_id = ?');
        $stmt->execute([$torneoId]);
        $cupoLleno = ((int) $stmt->fetchColumn()) >= (int) $torneo['max_participants'];

        $check = ReglasTorneo::validarSolicitud($torneo, $teamId, $playerId, $yaInscrito, $cupoLleno, (int) $torneo['organizer_id'] === $actorId);
        if (!$check['ok']) {
            throw new \RuntimeException($check['motivo'], 409);
        }

        $pdo->prepare(
            "INSERT INTO tournament_registrations (tournament_id, team_id, player_id, applicant_id, status, message, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'pending', ?, ?, ?)"
        )->execute([$torneoId, $teamId, $playerId, $actorId, $mensaje, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);

        $registroId = (int) $pdo->lastInsertId();
        $this->audit->registrar($actorId, 'registration', $registroId, 'inscripcion:solicitar', null, ['tournament_id' => $torneoId, 'team_id' => $teamId, 'player_id' => $playerId], $request);

        return $this->mostrar($registroId);
    }

    /**
     * Moderación: aceptar → crea el participante (respeta cupo); rechazar → status.
     */
    public function decidir(int $actorId, int $torneoId, int $registroId, array $data, ?Request $request = null): array
    {
        $torneo = $this->buscarTorneo($torneoId);
        if (!$this->torneos->esOrganizador($actorId, $torneo)) {
            throw new \RuntimeException('No eres el organizador de este torneo', 403);
        }

        $registro = $this->buscarRegistro($registroId, $torneoId);
        $accion = $data['action'] ?? null;

        $check = ReglasTorneo::puedeDecidir($registro['status'], $accion);
        if (!$check['ok']) {
            throw new \RuntimeException($check['motivo'], 409);
        }

        $pdo = $this->pdo();
        $nuevoEstado = $accion === 'accepted' ? 'accepted' : 'rejected';

        if ($accion === 'accepted') {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM tournament_participants WHERE tournament_id = ?');
            $stmt->execute([$torneoId]);
            $ocupados = (int) $stmt->fetchColumn();
            if ($ocupados >= (int) $torneo['max_participants']) {
                throw new \RuntimeException('El torneo alcanzó su cupo máximo', 409);
            }

            $pdo->prepare(
                'INSERT INTO tournament_participants (tournament_id, registration_id, team_id, player_id, seed, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$torneoId, $registroId, $registro['team_id'], $registro['player_id'], $ocupados + 1, date('Y-m-d H:i:s')]);
        }

        $pdo->prepare('UPDATE tournament_registrations SET status = ?, decided_by = ?, decided_at = ?, updated_at = ? WHERE id = ?')
            ->execute([$nuevoEstado, $actorId, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $registroId]);

        $this->audit->registrar($actorId, 'registration', $registroId, "inscripcion:{$accion}", $registro, null, $request);
        return $this->mostrar($registroId);
    }

    /**
     * El solicitante cancela su propia solicitud pendiente.
     */
    public function cancelar(int $actorId, int $torneoId, int $registroId, ?Request $request = null): array
    {
        $torneo = $this->buscarTorneo($torneoId);
        $registro = $this->buscarRegistro($registroId, $torneoId);

        $esOrganizador = (int) $torneo['organizer_id'] === $actorId;
        $esSolicitante = (int) $registro['applicant_id'] === $actorId;
        if (!$esOrganizador && !$esSolicitante) {
            throw new \RuntimeException('No puedes cancelar esta solicitud', 403);
        }
        if ($registro['status'] !== 'pending') {
            throw new \RuntimeException("La solicitud ya fue decidida ({$registro['status']})", 409);
        }

        $this->pdo()->prepare("UPDATE tournament_registrations SET status = 'cancelled', updated_at = ? WHERE id = ?")
            ->execute([date('Y-m-d H:i:s'), $registroId]);

        $this->audit->registrar($actorId, 'registration', $registroId, 'inscripcion:cancelar', $registro, null, $request);
        return $this->mostrar($registroId);
    }

    /**
     * Solicitudes del torneo (solo organizador), con nombres resueltos.
     */
    public function listar(int $torneoId, int $actorId, ?string $status = null): array
    {
        $torneo = $this->buscarTorneo($torneoId);
        if (!$this->torneos->esOrganizador($actorId, $torneo)) {
            throw new \RuntimeException('No eres el organizador de este torneo', 403);
        }

        $sql = 'SELECT r.*, COALESCE(t.name, p.name) AS display_name
                FROM tournament_registrations r
                LEFT JOIN teams t ON t.id = r.team_id
                LEFT JOIN players p ON p.id = r.player_id
                WHERE r.tournament_id = ?';
        $params = [$torneoId];
        if ($status !== null && in_array($status, ['pending', 'accepted', 'rejected', 'cancelled'], true)) {
            $sql .= ' AND r.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY r.created_at ASC';

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function mostrar(int $registroId): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT r.*, COALESCE(t.name, p.name) AS display_name
             FROM tournament_registrations r
             LEFT JOIN teams t ON t.id = r.team_id
             LEFT JOIN players p ON p.id = r.player_id
             WHERE r.id = ?'
        );
        $stmt->execute([$registroId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function buscarTorneo(int $torneoId): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM tournaments WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$torneoId]);
        $torneo = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$torneo) {
            throw new \RuntimeException('Torneo no encontrado', 404);
        }
        return $torneo;
    }

    private function buscarRegistro(int $registroId, int $torneoId): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM tournament_registrations WHERE id = ? AND tournament_id = ?');
        $stmt->execute([$registroId, $torneoId]);
        $registro = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$registro) {
            throw new \RuntimeException('Solicitud no encontrada', 404);
        }
        return $registro;
    }
}