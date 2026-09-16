<?php

namespace Tests\Feature;

use Apollo\Core\Database\Connection\DatabaseManager;
use Apollo\Core\Http\Request;
use Tests\TestCase;
use PDO;

/**
 * Ciclo de torneo (R-CYCLE-01, D-F0-7) por HTTP real:
 *  - liga/robin finaliza cuando no quedan partidos pendientes (y no antes),
 *  - pause/resume open ↔ paused con organizer y sin gate de email verificado.
 */
class TournamentLifecycleTest extends TestCase
{
    private static PDO $pdo;

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
    }

    private function dispatchJson(string $method, string $uri, array $body = [], string $token = ''): array
    {
        $server = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $uri,
            'HTTP_HOST' => 'localhost',
            'CONTENT_TYPE' => 'application/json',
        ];
        if ($token !== '') {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }

        $query = [];
        $queryString = parse_url($uri, PHP_URL_QUERY);
        if (is_string($queryString)) {
            parse_str($queryString, $query);
        }

        $response = self::$app->handle(new Request($query, [], [], [], [], $server, json_encode($body)));
        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)];
    }

    /** Registra+verifica+loguea un usuario y devuelve [token, id]. */
    private function usuarioVerificado(string $username, string $email): array
    {
        self::$pdo->prepare('DELETE FROM rate_limits')->execute();

        [$status, $body] = $this->dispatchJson('POST', '/api/auth/register', [
            'username' => $username,
            'email' => $email,
            'password' => 'clave-ciclo-1',
        ]);
        $this->assertSame(201, $status);

        self::$pdo->prepare('UPDATE users SET email_verified_at = ? WHERE email = ?')
            ->execute([date('Y-m-d H:i:s'), $email]);

        [$status, $body] = $this->dispatchJson('POST', '/api/auth/login', [
            'email' => $email,
            'password' => 'clave-ciclo-1',
        ]);
        $this->assertSame(200, $status);
        return [$body['data']['token'], (int) $body['data']['user']['id']];
    }

    private function crearTorneo(string $token, string $titulo, string $formato = 'round-robin'): int
    {
        [$status, $body] = $this->dispatchJson('POST', '/api/tournaments', [
            'title' => $titulo,
            'format' => $formato,
            'max_participants' => 8,
            'visibility' => 'publico',
        ], $token);
        $this->assertSame(201, $status);
        return (int) $body['data']['id'];
    }

    private function insertarPartido(int $tournamentId, string $status, int $round = 1): int
    {
        $stmt = self::$pdo->prepare('INSERT INTO matches (tournament_id, round_number, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$tournamentId, $round, $status, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
        return (int) self::$pdo->lastInsertId();
    }

    public function test_liga_finish_depends_on_pending_matches(): void
    {
        [$token] = $this->usuarioVerificado('org_ciclo_1', 'org.ciclo1@test.local');
        $tournamentId = $this->crearTorneo($token, 'Liga Cierre');

        [$status] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/publish", [], $token);
        $this->assertSame(200, $status, 'Publicar');

        // Jornadas: un partido completado y otro pendiente → no se puede cerrar.
        $round = $this->insertarPartido($tournamentId, 'completed');
        $pending = $this->insertarPartido($tournamentId, 'pending', 2);

        [$status] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/start", [], $token);
        $this->assertSame(200, $status, 'Iniciar liga con jornadas');

        [$status, $body] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/finish", [], $token);
        $this->assertSame(409, $status, 'Con un partido pendiente no se finaliza');
        $this->assertStringContainsString('partidos', $body['error'] ?? '');

        // Sin partidos pendientes → finaliza.
        self::$pdo->prepare("UPDATE matches SET status = 'completed' WHERE id = ?")->execute([$pending]);
        [$status, $body] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/finish", [], $token);
        $this->assertSame(200, $status, 'Liga sin partidos pendientes finaliza');
        $this->assertSame('finished', $body['data']['status']);
    }

    public function test_pause_and_resume_open_paused_cycle(): void
    {
        [$token] = $this->usuarioVerificado('org_ciclo_2', 'org.ciclo2@test.local');
        [$otherToken] = $this->usuarioVerificado('org_ciclo_3', 'org.ciclo3@test.local');

        self::$pdo->prepare('DELETE FROM rate_limits')->execute();
        $tournamentId = $this->crearTorneo($token, 'Copa Pausa');

        // draft → pause: 409 (solo desde open).
        [$status] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/pause", [], $token);
        $this->assertSame(409, $status, 'No se pausa un borrador');

        [$status] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/publish", [], $token);
        $this->assertSame(200, $status, 'Publicar');

        // Un tercero no puede pausar (organizer en el servicio) → 403.
        [$status] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/pause", [], $otherToken);
        $this->assertSame(403, $status, 'Solo el organizador pausa');

        // pause/resume no exigen email verificado (D-F0-7): se retira la verificación.
        self::$pdo->prepare('UPDATE users SET email_verified_at = NULL WHERE id = ?')
            ->execute([(int) self::$pdo->query("SELECT id FROM users WHERE username = 'org_ciclo_2'")->fetchColumn()]);

        [$status, $body] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/pause", [], $token);
        $this->assertSame(200, $status, 'Pausar sin gate de email');
        $this->assertSame('paused', $body['data']['status']);

        // Ya pausado: pausar de nuevo → 409.
        [$status] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/pause", [], $token);
        $this->assertSame(409, $status, 'No se repausa');

        [$status, $body] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/resume", [], $token);
        $this->assertSame(200, $status, 'Reanudar');
        $this->assertSame('open', $body['data']['status']);

        // Reanudar un torneo abierto → 409.
        [$status] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/resume", [], $token);
        $this->assertSame(409, $status, 'Solo se reanuda lo pausado');
    }

    public function test_bracket_finish_still_requires_final_winner(): void
    {
        [$token] = $this->usuarioVerificado('org_ciclo_4', 'org.ciclo4@test.local');
        $tournamentId = $this->crearTorneo($token, 'Copa Bracket', 'eliminacion-directa');

        [$status] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/publish", [], $token);
        $this->assertSame(200, $status);

        $stmt = self::$pdo->prepare("INSERT INTO draws (tournament_id, type, version, generated_at, created_at) VALUES (?, 'bracket', 1, ?, ?)");
        $stmt->execute([$tournamentId, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);

        [$status] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/start", [], $token);
        $this->assertSame(200, $status, 'Iniciar bracket con sorteo');

        // Aunque no queden partidos pendientes, sin final con ganador → 409.
        $this->insertarPartido($tournamentId, 'completed');
        [$status, $body] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/finish", [], $token);
        $this->assertSame(409, $status, 'Bracket sigue exigiendo final con ganador');
        $this->assertStringContainsString('final', $body['error'] ?? '');
    }
}
