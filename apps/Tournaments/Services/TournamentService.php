<?php

namespace Apps\Tournaments\Services;

use Apps\Tournaments\Repositories\TournamentRepository;
use Apollo\Core\Database\Connection\DatabaseManager;
use Apollo\Core\Http\Request;
use PDO;

class TournamentService
{
    public function __construct(
        private TournamentRepository $torneos,
        private AuditLogService $audit,
    ) {
    }

    /**
     * Listado público (explore): filtros + paginación, ENUMs mapeados a la API.
     */
    public function index(array $filtros, int $perPage = 20, int $page = 1): array
    {
        $filtros['visibility'] = Mapeos::visibilidadDesdeApi($filtros['visibility'] ?? null) ?? $filtros['visibility'] ?? null;
        $result = $this->torneos->filtrar($filtros, $perPage, $page);

        foreach ($result['data'] as &$t) {
            $t['format'] = Mapeos::formatoHaciaApi($t['format'] ?? null);
            $t['visibility'] = Mapeos::visibilidadHaciaApi($t['visibility'] ?? null);
            $t['deleted_at'] = null; // el repo no expone soft-delete en el listado público
        }

        return $result;
    }

    /**
     * Detalle de un torneo con sus sub-recursos de configuración.
     */
    public function mostrar(int $id): ?array
    {
        $torneo = $this->torneos->find($id);
        if (!$torneo || $torneo['deleted_at'] !== null) {
            return null;
        }

        $pdo = DatabaseManager::getConnection();

        $stmt = $pdo->prepare('SELECT * FROM tournament_prizes WHERE tournament_id = ? ORDER BY position ASC');
        $stmt->execute([$id]);
        $torneo['prizes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare('SELECT * FROM tournament_stats WHERE tournament_id = ? ORDER BY id ASC');
        $stmt->execute([$id]);
        $torneo['stats'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM tournament_registrations WHERE tournament_id = ? AND status = 'accepted'");
        $stmt->execute([$id]);
        $torneo['aceptados'] = (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'];

        $stmt = $pdo->prepare('SELECT COUNT(*) AS total FROM draws WHERE tournament_id = ?');
        $stmt->execute([$id]);
        $torneo['tiene_draw'] = ((int) $stmt->fetch(PDO::FETCH_ASSOC)['total']) > 0;

        $torneo['format'] = Mapeos::formatoHaciaApi($torneo['format'] ?? null);
        $torneo['visibility'] = Mapeos::visibilidadHaciaApi($torneo['visibility'] ?? null);

        return $torneo;
    }

    /**
     * Crea el torneo (borrador) con premios y stats opcionales.
     */
    public function crear(int $actorId, array $data, ?Request $request = null): ?array
    {
        $titulo = trim($data['title'] ?? '');
        $formato = Mapeos::formatoDesdeApi($data['format'] ?? null);
        $max = (int) ($data['max_participants'] ?? 0);

        if ($titulo === '') {
            throw new \InvalidArgumentException('El título del torneo es obligatorio');
        }
        if ($formato === null) {
            throw new \InvalidArgumentException('Formato inválido');
        }
        if ($max < 2) {
            throw new \InvalidArgumentException('El cupo máximo debe ser al menos 2');
        }

        $pdo = DatabaseManager::getConnection();
        $pdo->beginTransaction();
        try {
            $id = $this->torneos->create([
                'organizer_id' => $actorId,
                'season_id' => !empty($data['season_id']) ? (int) $data['season_id'] : null,
                'title' => $titulo,
                'sport' => trim($data['sport'] ?? ''),
                'description' => $data['description'] ?? null,
                'location' => $data['location'] ?? null,
                'is_online' => !empty($data['is_online']) ? 1 : 0,
                'image' => $data['image'] ?? null,
                'status' => 'draft',
                'format' => $formato,
                'max_participants' => $max,
                'is_individual' => !empty($data['is_individual']) ? 1 : 0,
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'registration_deadline' => $data['registration_deadline'] ?? null,
                'registration_fee' => $data['registration_fee'] ?? 0,
                'currency' => strtoupper($data['currency'] ?? 'USD'),
                'visibility' => Mapeos::visibilidadDesdeApi($data['visibility'] ?? 'publico') ?? 'public',
                'minimum_age' => !empty($data['minimum_age']) ? (int) $data['minimum_age'] : null,
                'rules' => $data['rules'] ?? null,
                'max_substitutes' => (int) ($data['max_substitutes'] ?? 0),
            ]);

            if (!empty($data['prizes']) && is_array($data['prizes'])) {
                $stmt = $pdo->prepare('INSERT INTO tournament_prizes (tournament_id, position, amount, currency, label) VALUES (?, ?, ?, ?, ?)');
                foreach (array_slice($data['prizes'], 0, 3) as $premio) {
                    $stmt->execute([
                        $id,
                        (int) ($premio['position'] ?? 1),
                        $premio['amount'] ?? 0,
                        strtoupper($premio['currency'] ?? $data['currency'] ?? 'USD'),
                        $premio['label'] ?? null,
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

        $this->audit->registrar($actorId, 'tournament', (int) $id, 'torneo:crear', null, ['title' => $titulo], $request);
        return $this->mostrar((int) $id);
    }

    /**
     * Actualiza aplicando las reglas de edición por estado (lib/edicion.ts).
     */
    public function actualizar(int $actorId, int $id, array $data, ?Request $request = null): ?array
    {
        $torneo = $this->torneos->find($id);
        if (!$torneo || $torneo['deleted_at'] !== null) {
            return null;
        }
        $this->verificarOrganizador($actorId, $torneo);

        $editables = ReglasTorneo::filtrarEditables($torneo['status'], $data);
        if ($editables === [] && $data !== []) {
            throw new \RuntimeException('Este torneo no admite edición en su estado actual');
        }

        $this->torneos->update($id, $editables);
        $this->audit->registrar($actorId, 'tournament', $id, 'torneo:actualizar', $torneo, $editables, $request);
        return $this->mostrar($id);
    }

    public function eliminar(int $actorId, int $id, ?Request $request = null): bool
    {
        $torneo = $this->torneos->find($id);
        if (!$torneo || $torneo['deleted_at'] !== null) {
            return false;
        }
        $this->verificarOrganizador($actorId, $torneo);

        $this->torneos->update($id, ['deleted_at' => date('Y-m-d H:i:s')]);
        $this->audit->registrar($actorId, 'tournament', $id, 'torneo:eliminar', $torneo, null, $request);
        return true;
    }

    /**
     * Transiciones del ciclo: publish (draft→open), start (open→live), finish (live→finished).
     */
    public function transicion(int $actorId, int $id, string $accion, ?Request $request = null): ?array
    {
        $torneo = $this->torneos->find($id);
        if (!$torneo || $torneo['deleted_at'] !== null) {
            return null;
        }
        $this->verificarOrganizador($actorId, $torneo);

        $estadoSiguiente = match ($accion) {
            'publish' => 'open',
            'start' => 'live',
            'finish' => 'finished',
            default => throw new \InvalidArgumentException('Acción inválida: publish, start o finish'),
        };

        $pdo = DatabaseManager::getConnection();

        if ($accion === 'publish') {
            $check = ReglasTorneo::puedePublicar($torneo + ['aceptados' => $this->contarAceptados($pdo, $id)]);
        } elseif ($accion === 'start') {
            $check = ReglasTorneo::puedeIniciar($torneo + ['tiene_draw' => $this->tieneDraw($pdo, $id)]);
        } else {
            $check = ReglasTorneo::puedeFinalizar($torneo + ['final_con_ganador' => $this->finalConGanador($pdo, $id)]);
        }

        if (!$check['ok']) {
            throw new \RuntimeException($check['motivo']);
        }

        $this->torneos->update($id, ['status' => $estadoSiguiente]);
        $this->audit->registrar($actorId, 'tournament', $id, "torneo:{$accion}", ['status' => $torneo['status']], ['status' => $estadoSiguiente], $request);

        return $this->mostrar($id);
    }

    /**
     * Lista de participantes resueltos (equipo o jugador) con su seed.
     */
    public function participantes(int $torneoId): array
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
        $stmt->execute([$torneoId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function esOrganizador(int $userId, array $torneo): bool
    {
        return (int) $torneo['organizer_id'] === (int) $userId;
    }

    private function verificarOrganizador(int $userId, array $torneo): void
    {
        if (!$this->esOrganizador($userId, $torneo)) {
            throw new \RuntimeException('No eres el organizador de este torneo', 403);
        }
    }

    private function contarAceptados(PDO $pdo, int $torneoId): int
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM tournament_registrations WHERE tournament_id = ? AND status = 'accepted'");
        $stmt->execute([$torneoId]);
        return (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    }

    private function tieneDraw(PDO $pdo, int $torneoId): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) AS total FROM draws WHERE tournament_id = ?');
        $stmt->execute([$torneoId]);
        return ((int) $stmt->fetch(PDO::FETCH_ASSOC)['total']) > 0;
    }

    private function finalConGanador(PDO $pdo, int $torneoId): bool
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS total FROM matches
             WHERE tournament_id = ?
               AND round_number = (SELECT MAX(round_number) FROM matches WHERE tournament_id = ?)
               AND status = 'completed'
               AND winner_participant_id IS NOT NULL"
        );
        $stmt->execute([$torneoId, $torneoId]);
        return ((int) $stmt->fetch(PDO::FETCH_ASSOC)['total']) > 0;
    }
}