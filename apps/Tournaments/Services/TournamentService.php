<?php

namespace Apps\Tournaments\Services;

use Apps\Tournaments\Repositories\TournamentRepository;
use Apollo\Core\Database\Connection\DatabaseManager;
use Apollo\Core\Http\Request;
use PDO;

class TournamentService
{
    public function __construct(
        private TournamentRepository $tournaments,
        private AuditLogService $audit,
    ) {
    }

    /**
     * Public listing (explore): filters + pagination, ENUMs mapped to the API.
     */
    public function index(array $filters, int $perPage = 20, int $page = 1): array
    {
        $filters['visibility'] = Mappings::visibilityFromApi($filters['visibility'] ?? null) ?? $filters['visibility'] ?? null;
        $result = $this->tournaments->filter($filters, $perPage, $page);

        foreach ($result['data'] as &$t) {
            $t['format'] = Mappings::formatToApi($t['format'] ?? null);
            $t['visibility'] = Mappings::visibilityToApi($t['visibility'] ?? null);
            $t['deleted_at'] = null; // the repository does not expose soft-delete in the public listing
        }

        return $result;
    }

    /**
     * Tournament detail with its configuration sub-resources.
     */
    public function show(int $id): ?array
    {
        $tournament = $this->tournaments->find($id);
        if (!$tournament || $tournament['deleted_at'] !== null) {
            return null;
        }

        $pdo = DatabaseManager::getConnection();

        $stmt = $pdo->prepare('SELECT * FROM tournament_prizes WHERE tournament_id = ? ORDER BY position ASC');
        $stmt->execute([$id]);
        $tournament['prizes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare('SELECT * FROM tournament_stats WHERE tournament_id = ? ORDER BY id ASC');
        $stmt->execute([$id]);
        $tournament['stats'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM tournament_registrations WHERE tournament_id = ? AND status = 'accepted'");
        $stmt->execute([$id]);
        $tournament['aceptados'] = (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'];

        $stmt = $pdo->prepare('SELECT COUNT(*) AS total FROM draws WHERE tournament_id = ?');
        $stmt->execute([$id]);
        $tournament['tiene_draw'] = ((int) $stmt->fetch(PDO::FETCH_ASSOC)['total']) > 0;

        $tournament['format'] = Mappings::formatToApi($tournament['format'] ?? null);
        $tournament['visibility'] = Mappings::visibilityToApi($tournament['visibility'] ?? null);

        return $tournament;
    }

    /**
     * Creates the tournament (draft) with optional prizes and stats.
     */
    public function create(int $actorId, array $data, ?Request $request = null): ?array
    {
        $title = trim($data['title'] ?? '');
        $format = Mappings::formatFromApi($data['format'] ?? null);
        $maxParticipants = (int) ($data['max_participants'] ?? 0);

        if ($title === '') {
            throw new \InvalidArgumentException('El título del torneo es obligatorio');
        }
        if ($format === null) {
            throw new \InvalidArgumentException('Formato inválido');
        }
        if ($maxParticipants < 2) {
            throw new \InvalidArgumentException('El cupo máximo debe ser al menos 2');
        }

        $pdo = DatabaseManager::getConnection();
        $pdo->beginTransaction();
        try {
            $id = $this->tournaments->create([
                'organizer_id' => $actorId,
                'season_id' => !empty($data['season_id']) ? (int) $data['season_id'] : null,
                'title' => $title,
                'sport' => trim($data['sport'] ?? ''),
                'description' => $data['description'] ?? null,
                'location' => $data['location'] ?? null,
                'is_online' => !empty($data['is_online']) ? 1 : 0,
                'image' => $data['image'] ?? null,
                'status' => 'draft',
                'format' => $format,
                'max_participants' => $maxParticipants,
                'is_individual' => !empty($data['is_individual']) ? 1 : 0,
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'registration_deadline' => $data['registration_deadline'] ?? null,
                'registration_fee' => $data['registration_fee'] ?? 0,
                'currency' => strtoupper($data['currency'] ?? 'USD'),
                'visibility' => Mappings::visibilityFromApi($data['visibility'] ?? 'publico') ?? 'public',
                'minimum_age' => !empty($data['minimum_age']) ? (int) $data['minimum_age'] : null,
                'rules' => $data['rules'] ?? null,
                'max_substitutes' => (int) ($data['max_substitutes'] ?? 0),
            ]);

            if (!empty($data['prizes']) && is_array($data['prizes'])) {
                $stmt = $pdo->prepare('INSERT INTO tournament_prizes (tournament_id, position, amount, currency, label) VALUES (?, ?, ?, ?, ?)');
                foreach (array_slice($data['prizes'], 0, 3) as $prize) {
                    $stmt->execute([
                        $id,
                        (int) ($prize['position'] ?? 1),
                        $prize['amount'] ?? 0,
                        strtoupper($prize['currency'] ?? $data['currency'] ?? 'USD'),
                        $prize['label'] ?? null,
                    ]);
                }
            }

            if (!empty($data['stats']) && is_array($data['stats'])) {
                $stmt = $pdo->prepare('INSERT INTO tournament_stats (tournament_id, label, type, per_player) VALUES (?, ?, ?, ?)');
                foreach ($data['stats'] as $stat) {
                    $stmt->execute([
                        $id,
                        $stat['label'] ?? 'Puntos',
                        in_array($stat['type'] ?? null, ['number', 'boolean'], true) ? $stat['type'] : 'number',
                        !empty($stat['per_player']) ? 1 : 0,
                    ]);
                }
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $this->audit->record($actorId, 'tournament', (int) $id, 'torneo:crear', null, ['title' => $title], $request);
        return $this->show((int) $id);
    }

    /**
     * Updates applying the per-state editing rules (lib/edicion.ts).
     */
    public function update(int $actorId, int $id, array $data, ?Request $request = null): ?array
    {
        $tournament = $this->tournaments->find($id);
        if (!$tournament || $tournament['deleted_at'] !== null) {
            return null;
        }
        $this->ensureOrganizer($actorId, $tournament);

        $editable = TournamentRules::filterEditableFields($tournament['status'], $data);
        if ($editable === [] && $data !== []) {
            throw new \RuntimeException('Este torneo no admite edición en su estado actual');
        }

        $this->tournaments->update($id, $editable);
        $this->audit->record($actorId, 'tournament', $id, 'torneo:actualizar', $tournament, $editable, $request);
        return $this->show($id);
    }

    public function delete(int $actorId, int $id, ?Request $request = null): bool
    {
        $tournament = $this->tournaments->find($id);
        if (!$tournament || $tournament['deleted_at'] !== null) {
            return false;
        }
        $this->ensureOrganizer($actorId, $tournament);

        $this->tournaments->update($id, ['deleted_at' => date('Y-m-d H:i:s')]);
        $this->audit->record($actorId, 'tournament', $id, 'torneo:eliminar', $tournament, null, $request);
        return true;
    }

    /**
     * Copies the tournament as a new draft: title " (copia)", no participants,
     * draw, matches or registrations; prizes and stats are copied.
     */
    public function duplicate(int $actorId, int $id, ?Request $request = null): ?array
    {
        $tournament = $this->tournaments->find($id);
        if (!$tournament || $tournament['deleted_at'] !== null) {
            return null;
        }
        $this->ensureOrganizer($actorId, $tournament);

        $pdo = DatabaseManager::getConnection();
        $pdo->beginTransaction();
        try {
            $newId = $this->tournaments->create([
                'organizer_id' => $actorId,
                'season_id' => $tournament['season_id'],
                'title' => $tournament['title'] . ' (copia)',
                'sport' => $tournament['sport'],
                'description' => $tournament['description'],
                'location' => $tournament['location'],
                'is_online' => $tournament['is_online'],
                'image' => $tournament['image'],
                'status' => 'draft',
                'format' => $tournament['format'],
                'max_participants' => $tournament['max_participants'],
                'is_individual' => $tournament['is_individual'],
                'start_date' => $tournament['start_date'],
                'end_date' => $tournament['end_date'],
                'registration_deadline' => $tournament['registration_deadline'],
                'registration_fee' => $tournament['registration_fee'],
                'currency' => $tournament['currency'],
                'visibility' => $tournament['visibility'],
                'minimum_age' => $tournament['minimum_age'],
                'rules' => $tournament['rules'],
                'max_substitutes' => $tournament['max_substitutes'],
            ]);

            $stmt = $pdo->prepare('SELECT * FROM tournament_prizes WHERE tournament_id = ? ORDER BY position ASC');
            $stmt->execute([$id]);
            $prizeStmt = $pdo->prepare('INSERT INTO tournament_prizes (tournament_id, position, amount, currency, label) VALUES (?, ?, ?, ?, ?)');
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $prize) {
                $prizeStmt->execute([$newId, $prize['position'], $prize['amount'], $prize['currency'], $prize['label']]);
            }

            $stmt = $pdo->prepare('SELECT * FROM tournament_stats WHERE tournament_id = ? ORDER BY id ASC');
            $stmt->execute([$id]);
            $statStmt = $pdo->prepare('INSERT INTO tournament_stats (tournament_id, label, type, per_player) VALUES (?, ?, ?, ?)');
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $stat) {
                $statStmt->execute([$newId, $stat['label'], $stat['type'], $stat['per_player']]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $this->audit->record($actorId, 'tournament', (int) $newId, 'torneo:duplicar', ['from' => $id], ['title' => $tournament['title'] . ' (copia)'], $request);
        return $this->show((int) $newId);
    }

    /**
     * Lifecycle transitions: publish (draft→open), start (open→live), finish (live→finished).
     */
    public function transition(int $actorId, int $id, string $action, ?Request $request = null): ?array
    {
        $tournament = $this->tournaments->find($id);
        if (!$tournament || $tournament['deleted_at'] !== null) {
            return null;
        }
        $this->ensureOrganizer($actorId, $tournament);

        $nextStatus = match ($action) {
            'publish' => 'open',
            'start' => 'live',
            'finish' => 'finished',
            default => throw new \InvalidArgumentException('Acción inválida: publish, start o finish'),
        };

        $pdo = DatabaseManager::getConnection();

        if ($action === 'publish') {
            $check = TournamentRules::canPublish($tournament + ['aceptados' => $this->countAccepted($pdo, $id)]);
        } elseif ($action === 'start') {
            $check = TournamentRules::canStart($tournament + ['tiene_draw' => $this->hasDraw($pdo, $id)]);
        } else {
            $check = TournamentRules::canFinish($tournament + ['final_con_ganador' => $this->hasFinalWinner($pdo, $id)]);
        }

        if (!$check['ok']) {
            throw new \RuntimeException($check['reason']);
        }

        $this->tournaments->update($id, ['status' => $nextStatus]);
        $this->audit->record($actorId, 'tournament', $id, "torneo:{$action}", ['status' => $tournament['status']], ['status' => $nextStatus], $request);

        return $this->show($id);
    }

    /**
     * Resolved participants (team or player) with their seed.
     */
    public function participants(int $tournamentId): array
    {
        $pdo = DatabaseManager::getConnection();
        $stmt = $pdo->prepare(
            'SELECT tp.id, tp.tournament_id, tp.team_id, tp.player_id, tp.seed,
                    COALESCE(t.name, p.name) AS display_name
             FROM tournament_participants tp
             LEFT JOIN teams t ON t.id = tp.team_id
             LEFT JOIN players p ON p.id = tp.player_id
             WHERE tp.tournament_id = ?
             ORDER BY tp.seed ASC, tp.id ASC'
        );
        $stmt->execute([$tournamentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function isOrganizer(int $userId, array $tournament): bool
    {
        return (int) $tournament['organizer_id'] === (int) $userId;
    }

    private function ensureOrganizer(int $userId, array $tournament): void
    {
        if (!$this->isOrganizer($userId, $tournament)) {
            throw new \RuntimeException('No eres el organizador de este torneo', 403);
        }
    }

    private function countAccepted(PDO $pdo, int $tournamentId): int
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM tournament_registrations WHERE tournament_id = ? AND status = 'accepted'");
        $stmt->execute([$tournamentId]);
        return (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    }

    private function hasDraw(PDO $pdo, int $tournamentId): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) AS total FROM draws WHERE tournament_id = ?');
        $stmt->execute([$tournamentId]);
        return ((int) $stmt->fetch(PDO::FETCH_ASSOC)['total']) > 0;
    }

    private function hasFinalWinner(PDO $pdo, int $tournamentId): bool
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS total FROM matches
             WHERE tournament_id = ?
               AND round_number = (SELECT MAX(round_number) FROM matches WHERE tournament_id = ?)
               AND status = 'completed'
               AND winner_participant_id IS NOT NULL"
        );
        $stmt->execute([$tournamentId, $tournamentId]);
        return ((int) $stmt->fetch(PDO::FETCH_ASSOC)['total']) > 0;
    }
}