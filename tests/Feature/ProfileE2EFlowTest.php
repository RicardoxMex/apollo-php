<?php

namespace Tests\Feature;

use Apollo\Core\Database\Connection\DatabaseManager;
use Apollo\Core\Http\Request;
use Tests\TestCase;
use PDO;

/**
 * E2E del perfil (M3, AC-05) a través del kernel real: register → login →
 * PUT /auth/profile (persistido) → torneo + equipo (capitán) + inscripción
 * aceptada → GET /profile/history con los tres grupos → 401 sin token.
 * SQLite :memory: con migraciones reales.
 */
class ProfileE2EFlowTest extends TestCase
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
        $response = self::$app->handle(new Request([], [], [], [], [], $server, json_encode($body)));
        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)];
    }

    public function test_e2e_perfil_edicion_e_historial(): void
    {
        // ── Registro + login ──
        [$status, $body] = $this->dispatchJson('POST', '/api/auth/register', [
            'username' => 'org_perfil_e2e',
            'email' => 'org.perfil.e2e@test.local',
            'password' => 'clave-perfil-1',
            'first_name' => 'Organizador',
        ]);
        $this->assertSame(201, $status);

        [$status, $body] = $this->dispatchJson('POST', '/api/auth/login', [
            'email' => 'org.perfil.e2e@test.local',
            'password' => 'clave-perfil-1',
        ]);
        $this->assertSame(200, $status);
        $token = $body['data']['token'];

        // ── AC-05a: editar perfil y que persista ──
        [$status, $body] = $this->dispatchJson('PUT', '/api/auth/profile', [
            'first_name' => 'Org',
            'last_name' => 'E2E',
            'phone' => '+52 1 55 0000 1111',
        ], $token);
        $this->assertSame(200, $status, 'PUT /auth/profile 200');
        $this->assertSame('Org E2E', $body['data']['user']['full_name']);

        [$status, $body] = $this->dispatchJson('GET', '/api/auth/profile', [], $token);
        $this->assertSame(200, $status);
        $this->assertSame('Org', $body['data']['user']['first_name']);
        $this->assertSame('E2E', $body['data']['user']['last_name']);
        $this->assertSame('+52 1 55 0000 1111', $body['data']['user']['phone']);

        // ── Datos para el historial: torneo + equipo (capitán) + inscripción aceptada ──
        [$status, $body] = $this->dispatchJson('POST', '/api/tournaments', [
            'title' => 'Copa Perfil',
            'format' => 'round-robin',
            'max_participants' => 8,
            'visibility' => 'publico',
            'sport' => 'Fútbol',
        ], $token);
        $this->assertSame(201, $status);
        $tournamentId = $body['data']['id'];

        // Público: para que el jugador pueda aplicar (D2: exige email verificado).
        $stmt = self::$pdo->prepare('SELECT token FROM email_verifications WHERE user_id = (SELECT id FROM users WHERE email = ?) ORDER BY id DESC LIMIT 1');
        $stmt->execute(['org.perfil.e2e@test.local']);
        $hash = $stmt->fetchColumn();
        $this->assertNotFalse($hash, 'Token de verificación emitido');
        $verifyToken = 'e2e-profile-' . bin2hex(random_bytes(8));
        self::$pdo->prepare('UPDATE email_verifications SET token = ? WHERE token = ?')
            ->execute([hash('sha256', $verifyToken), $hash]);
        [$status] = $this->dispatchJson('POST', '/api/auth/verify-email', ['token' => $verifyToken]);
        $this->assertSame(200, $status, 'Email verificado');

        [$status] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/publish", [], $token);
        $this->assertSame(200, $status, 'Torneo publicado');

        // El organizador crea un equipo (queda como capitán) y se inscribe.
        [$status, $body] = $this->dispatchJson('POST', '/api/teams', ['name' => 'Perfil FC'], $token);
        $this->assertSame(201, $status);
        $teamId = $body['data']['id'];

        // ── AC-05b: historial con los tres grupos ──
        [$status, $body] = $this->dispatchJson('GET', '/api/profile/history', [], $token);
        $this->assertSame(200, $status);
        $this->assertCount(1, $body['data']['organized'], 'Torneo organizado');
        $this->assertSame('Copa Perfil', $body['data']['organized'][0]['title']);
        $this->assertSame('round-robin', $body['data']['organized'][0]['format']);
        $this->assertCount(1, $body['data']['teams'], 'Equipo donde es capitán');
        $this->assertSame('Perfil FC', $body['data']['teams'][0]['name']);
        $this->assertSame(1, (int) $body['data']['teams'][0]['is_captain']);
        $this->assertEmpty($body['data']['participated'], 'Sin inscripciones aceptadas propias todavía');

        // Un segundo usuario se inscribe y es aceptado → participa.
        [$status] = $this->dispatchJson('POST', '/api/auth/register', [
            'username' => 'jugador_perfil',
            'email' => 'jug.perfil.e2e@test.local',
            'password' => 'clave-jugador-9',
        ]);
        $this->assertSame(201, $status);
        [$status, $body] = $this->dispatchJson('POST', '/api/auth/login', [
            'email' => 'jug.perfil.e2e@test.local',
            'password' => 'clave-jugador-9',
        ]);
        $this->assertSame(200, $status);
        $jugToken = $body['data']['token'];

        [$status, $body] = $this->dispatchJson('POST', '/api/teams', ['name' => 'Visitantes FC'], $jugToken);
        $this->assertSame(201, $status);
        $jugTeamId = $body['data']['id'];

        [$status, $body] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/registrations", ['team_id' => $jugTeamId], $jugToken);
        $this->assertSame(201, $status);
        $registrationId = $body['data']['id'];
        [$status] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/registrations/{$registrationId}/decide", ['action' => 'accepted'], $token);
        $this->assertSame(200, $status);

        // Historial del jugador: participado + equipo.
        [$status, $body] = $this->dispatchJson('GET', '/api/profile/history', [], $jugToken);
        $this->assertSame(200, $status);
        $this->assertCount(1, $body['data']['participated'], 'Inscripción aceptada');
        $this->assertSame('Copa Perfil', $body['data']['participated'][0]['title']);
        $this->assertCount(1, $body['data']['teams']);
        $this->assertSame('Visitantes FC', $body['data']['teams'][0]['name']);

        // ── Sin token → 401 ──
        [$status] = $this->dispatchJson('GET', '/api/profile/history');
        $this->assertSame(401, $status);
        [$status] = $this->dispatchJson('PUT', '/api/auth/profile', ['first_name' => 'X']);
        $this->assertSame(401, $status);
    }
}