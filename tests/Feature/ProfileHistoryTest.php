<?php

namespace Tests\Feature;

use Apollo\Core\Database\Connection\DatabaseManager;
use Apollo\Core\Http\Request;
use Apps\Tournaments\Services\ProfileHistoryService;
use Tests\TestCase;
use PDO;

/**
 * Historial del perfil (PROFILE-02, REQ-13/14): torneos organizados,
 * participados (inscripción aceptada) y equipos (capitán o jugador).
 * SQLite :memory: con migraciones reales.
 */
class ProfileHistoryTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static ProfileHistoryService $history;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $_ENV['DB_CONNECTION'] = 'sqlite';
        $_ENV['DB_DRIVER'] = 'sqlite';
        $_ENV['DB_DATABASE'] = ':memory:';

        $config = self::$app->make('config');
        foreach ($config->get('providers.core', []) as $providerClass) {
            if (class_exists($providerClass)) {
                self::$app->registerServiceProvider(new $providerClass(self::$app));
            }
        }
        foreach ($config->get('providers.app', []) as $providerClass) {
            if (class_exists($providerClass)) {
                self::$app->registerServiceProvider(new $providerClass(self::$app));
            }
        }
        foreach ($config->get('apps.registered', []) as $appName) {
            try {
                self::$app->registerApp($appName);
            } catch (\Throwable $e) {
            }
        }
        self::$app->bootServiceProviders();
        DatabaseManager::disconnect();
        self::$pdo = DatabaseManager::getConnection();

        $files = glob(dirname(__DIR__, 2) . '/database/migrations/*.php');
        sort($files);
        foreach ($files as $file) {
            $migration = require $file;
            $migration->up();
        }

        self::$history = new ProfileHistoryService();
    }

    private function createUser(string $username, string $email): int
    {
        self::$pdo->prepare("INSERT INTO users (username, email, password, status) VALUES (?, ?, 'x', 'active')")
            ->execute([$username, $email]);
        return (int) self::$pdo->lastInsertId();
    }

    private function createTournament(int $organizerId, string $title, string $status = 'draft'): int
    {
        self::$pdo->prepare(
            "INSERT INTO tournaments (organizer_id, title, slug, sport, status, format, max_participants, visibility, created_at, updated_at)
             VALUES (?, ?, ?, 'Fútbol', ?, 'round_robin', 8, 'public', ?, ?)"
        )->execute([$organizerId, $title, 'slug-' . bin2hex(random_bytes(3)), $status, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
        return (int) self::$pdo->lastInsertId();
    }

    public function test_history_organized_participated_and_teams(): void
    {
        $organizer = $this->createUser('hist1', 'hist1@test.local');
        $applicant = $this->createUser('hist2', 'hist2@test.local');
        $tercero = $this->createUser('hist3', 'hist3@test.local');

        // Organizador: 1 torneo activo + 1 soft-deleted (debe excluirse).
        $torneo = $this->createTournament($organizer, 'Copa Historial', 'open');
        $borrado = $this->createTournament($organizer, 'Copa Borrada', 'draft');
        self::$pdo->prepare('UPDATE tournaments SET deleted_at = ? WHERE id = ?')
            ->execute([date('Y-m-d H:i:s'), $borrado]);

        // Participado: inscripción aceptada del applicant en el torneo del organizer.
        self::$pdo->prepare("INSERT INTO tournament_registrations (tournament_id, applicant_id, status, created_at, updated_at) VALUES (?, ?, 'accepted', ?, ?)")
            ->execute([$torneo, $applicant, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
        // Rechazada: no cuenta como participado (torneo de un tercero).
        $otro = $this->createTournament($tercero, 'Copa Rechazada', 'open');
        self::$pdo->prepare("INSERT INTO tournament_registrations (tournament_id, applicant_id, status, created_at, updated_at) VALUES (?, ?, 'rejected', ?, ?)")
            ->execute([$otro, $applicant, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);

        // Equipos: el applicant es capitán de uno y jugador de otro.
        self::$pdo->prepare("INSERT INTO teams (name, created_at, updated_at) VALUES ('Equipo Capitán', ?, ?)")
            ->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
        $teamCap = (int) self::$pdo->lastInsertId();
        self::$pdo->prepare('INSERT INTO team_captains (team_id, user_id) VALUES (?, ?)')
            ->execute([$teamCap, $applicant]);

        self::$pdo->prepare("INSERT INTO teams (name, created_at, updated_at) VALUES ('Equipo Jugador', ?, ?)")
            ->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
        $teamPlay = (int) self::$pdo->lastInsertId();
        self::$pdo->prepare("INSERT INTO players (user_id, name, jersey_number, created_at, updated_at) VALUES (?, 'Jugador', 7, ?, ?)")
            ->execute([$applicant, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
        $playerId = (int) self::$pdo->lastInsertId();
        self::$pdo->prepare('INSERT INTO team_players (team_id, player_id, jersey_number, joined_at) VALUES (?, ?, 7, ?)')
            ->execute([$teamPlay, $playerId, date('Y-m-d H:i:s')]);

        // ── Organizador ──
        $h = self::$history->history($organizer);
        $this->assertCount(1, $h['organized'], 'Solo el torneo activo (el borrado se excluye)');
        $this->assertSame('Copa Historial', $h['organized'][0]['title']);
        $this->assertSame('round-robin', $h['organized'][0]['format'], 'ENUM mapeado a API');
        $this->assertSame('publico', $h['organized'][0]['visibility']);
        $this->assertEmpty($h['participated'], 'El organizador no participa');
        $this->assertEmpty($h['teams']);

        // ── Solicitante ──
        $h = self::$history->history($applicant);
        $this->assertEmpty($h['organized']);
        $this->assertCount(1, $h['participated'], 'Solo la inscripción aceptada');
        $this->assertSame('Copa Historial', $h['participated'][0]['title']);

        $this->assertCount(2, $h['teams']);
        $nombres = array_column($h['teams'], 'name');
        sort($nombres);
        $this->assertSame(['Equipo Capitán', 'Equipo Jugador'], $nombres);
        $capitan = array_values(array_filter($h['teams'], fn($t) => $t['name'] === 'Equipo Capitán'))[0];
        $this->assertSame(1, (int) $capitan['is_captain']);
        $jugador = array_values(array_filter($h['teams'], fn($t) => $t['name'] === 'Equipo Jugador'))[0];
        $this->assertSame(0, (int) $jugador['is_captain']);
    }

    public function test_history_endpoint_requires_auth(): void
    {
        $server = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/profile/history', 'HTTP_HOST' => 'localhost'];
        $response = self::$app->handle(new Request([], [], [], [], [], $server, null));
        $this->assertSame(401, $response->getStatusCode());
    }
}