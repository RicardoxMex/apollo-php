<?php

namespace Apps\Tournaments\Services;

use Apollo\Core\Database\Connection\DatabaseManager;
use PDO;

/**
 * Historial del perfil de usuario (M3, REQ-13/14):
 * - organized: torneos donde el usuario es organizador (sin soft-delete).
 * - participated: torneos donde tiene una inscripción aceptada.
 * - teams: equipos donde es capitán (team_captains) o jugador
 *   (players.user_id), con número de jugadores.
 */
class ProfileHistoryService
{
    public function history(int $userId): array
    {
        $pdo = DatabaseManager::getConnection();

        return [
            'organized' => $this->organized($pdo, $userId),
            'participated' => $this->participated($pdo, $userId),
            'teams' => $this->teams($pdo, $userId),
        ];
    }

    private function organized(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare(
            "SELECT id, title, slug, sport, status, format, max_participants, visibility, start_date, end_date
             FROM tournaments
             WHERE organizer_id = ? AND deleted_at IS NULL
             ORDER BY updated_at DESC"
        );
        $stmt->execute([$userId]);
        return $this->mapTournaments($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function participated(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare(
            "SELECT t.id, t.title, t.slug, t.sport, t.status, t.format, t.max_participants, t.visibility, t.start_date, t.end_date
             FROM tournament_registrations r
             JOIN tournaments t ON t.id = r.tournament_id
             WHERE r.applicant_id = ? AND r.status = 'accepted' AND t.deleted_at IS NULL
             GROUP BY t.id
             ORDER BY t.updated_at DESC"
        );
        $stmt->execute([$userId]);
        return $this->mapTournaments($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function mapTournaments(array $rows): array
    {
        foreach ($rows as &$t) {
            $t['format'] = Mappings::formatToApi($t['format'] ?? null);
            $t['visibility'] = Mappings::visibilityToApi($t['visibility'] ?? null);
        }
        unset($t);
        return $rows;
    }

    private function teams(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare(
            "SELECT t.id, t.name, t.contact, t.image,
                    (SELECT COUNT(*) FROM team_players tp WHERE tp.team_id = t.id AND tp.left_at IS NULL) AS players_count,
                    EXISTS(SELECT 1 FROM team_captains tc WHERE tc.team_id = t.id AND tc.user_id = ?) AS is_captain
             FROM teams t
             WHERE t.deleted_at IS NULL
               AND (
                   EXISTS(SELECT 1 FROM team_captains tc WHERE tc.team_id = t.id AND tc.user_id = ?)
                   OR EXISTS(SELECT 1 FROM players p JOIN team_players tp ON tp.player_id = p.id
                             WHERE tp.team_id = t.id AND p.user_id = ? AND tp.left_at IS NULL)
               )
             ORDER BY t.name ASC"
        );
        $stmt->execute([$userId, $userId, $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}