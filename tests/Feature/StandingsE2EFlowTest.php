<?php

namespace Tests\Feature;

use Apollo\Core\Database\Connection\DatabaseManager;
use Apollo\Core\Http\Request;
use Apollo\Core\Mail\Transports\LogTransport;
use Apollo\Core\Mail\Mailer;
use Apollo\Core\Mail\MailMessage;
use Apps\Tournaments\Services\StandingsService;
use Tests\TestCase;
use PDO;

/**
 * E2E de la clasificación (M2, AC-04) a través del kernel real (in-process):
 * torneo → participantes → partidos con resultados → GET /standings devuelve
 * la tabla del servidor (misma que el frontend mock para el mismo dataset);
 * editar un marcador cambia la tabla (cache invalidada).
 * SQLite :memory: con migraciones reales.
 */
class StandingsE2EFlowTest extends TestCase
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

    private function dispatchGet(string $uri): array
    {
        $server = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $uri, 'HTTP_HOST' => 'localhost'];
        $response = self::$app->handle(new Request([], [], [], [], [], $server, null));
        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)];
    }

    private function dispatchJson(string $method, string $uri, array $body, string $token = ''): array
    {
        $server = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $uri,
            'HTTP_HOST' => 'localhost',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ];
        $response = self::$app->handle(new Request([], [], [], [], [], $server, json_encode($body)));
        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)];
    }

    public function test_e2e_clasificacion_servidor_paridad_y_cache(): void
    {
        // ── Organizador + torneo round-robin con stat de goles ──
        [$status, $body] = $this->dispatchJson('POST', '/api/auth/register', [
            'username' => 'org_stand_e2e',
            'email' => 'org.stand.e2e@test.local',
            'password' => 'clave-stand-1',
        ]);
        $this->assertSame(201, $status);

        [$status, $body] = $this->dispatchJson('POST', '/api/auth/login', [
            'email' => 'org.stand.e2e@test.local',
            'password' => 'clave-stand-1',
        ]);
        $this->assertSame(200, $status);
        $token = $body['data']['token'];

        [$status, $body] = $this->dispatchJson('POST', '/api/tournaments', [
            'title' => 'Copa Standings',
            'format' => 'round-robin',
            'max_participants' => 4,
            'visibility' => 'publico',
            'sport' => 'Fútbol',
            'stats' => [['label' => 'Goles', 'type' => 'number']],
        ], $token);
        $this->assertSame(201, $status);
        $tournamentId = $body['data']['id'];

        // ── 4 equipos (participantes) ──
        $participants = [];
        foreach (['Alpha FC', 'Beta FC', 'Gamma FC', 'Delta FC'] as $name) {
            [$status, $body] = $this->dispatchJson('POST', '/api/teams', ['name' => $name], $token);
            $this->assertSame(201, $status);
            $participants[] = $body['data']['id'];
        }

        // Inscripción directa del organizador en borrador → participantes aceptados.
        foreach ($participants as $teamId) {
            [$status, $body] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/registrations", ['team_id' => $teamId], $token);
            $this->assertSame(201, $status);
            [$status] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/registrations/{$body['data']['id']}/decide", ['action' => 'accepted'], $token);
            $this->assertSame(200, $status);
        }

        [$status, $body] = $this->dispatchGet("/api/tournaments/{$tournamentId}/participants");
        $this->assertSame(200, $status);
        $this->assertCount(4, $body['data']);
        $p = array_map(fn($x) => (int) $x['id'], $body['data']);

        // ── Partidos con resultados (2-1, 1-1, 0-0, 3-0) ──
        $marcadores = [
            [$p[0], $p[1], 2, 1],
            [$p[2], $p[3], 1, 1],
            [$p[0], $p[2], 0, 0],
            [$p[1], $p[3], 3, 0],
        ];
        $statId = $body['data'] ?: null;
        // El stat id se obtiene del detalle del torneo.
        [$status, $body] = $this->dispatchGet("/api/tournaments/{$tournamentId}");
        $statId = (int) $body['data']['stats'][0]['id'];

        $matchIds = [];
        foreach ($marcadores as $i => [$a, $b, $sa, $sb]) {
            [$status, $body] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/matches", [
                'round_number' => $i + 1,
                'label' => 'J' . ($i + 1),
                'participant_a_id' => $a,
                'participant_b_id' => $b,
                'status' => 'scheduled',
                // Programado exige fecha (regla de programación manual).
                'scheduled_at' => '2026-09-10 10:00:00',
            ], $token);
            $this->assertSame(201, $status, "Partido {$i} creado");
            $matchIds[] = (int) $body['data']['id'];
        }

        // Los resultados se registran vía PUT (update) con status completed.
        foreach ($marcadores as $i => [$a, $b, $sa, $sb]) {
            [$status] = $this->dispatchJson('PUT', "/api/tournaments/{$tournamentId}/matches/{$matchIds[$i]}", [
                'status' => 'completed',
                'scores' => [['stat_id' => $statId, 'a' => $sa, 'b' => $sb]],
            ], $token);
            $this->assertSame(200, $status, "Resultado {$i} registrado");
        }

        // ── AC-04: la tabla del servidor = la del frontend mock (mismo dataset) ──
        [$status, $body] = $this->dispatchGet("/api/tournaments/{$tournamentId}/standings");
        $this->assertSame(200, $status);
        $rows = $body['data']['standings'];

        $this->assertCount(4, $rows);
        $this->assertSame(['Alpha FC', 'Beta FC', 'Gamma FC', 'Delta FC'], array_column($rows, 'name'));

        $a = $rows[0];
        $this->assertSame(['pj' => 2, 'g' => 1, 'e' => 1, 'pts' => 4], ['pj' => $a['pj'], 'g' => $a['g'], 'e' => $a['e'], 'pts' => $a['pts']]);
        // A través del JSON del HTTP los decimales enteros llegan como int.
        $this->assertSame([2, 1], [$a['af'], $a['ec']]);

        $b = $rows[1];
        $this->assertSame(['g' => 1, 'p' => 1, 'pts' => 3], ['g' => $b['g'], 'p' => $b['p'], 'pts' => $b['pts']]);
        $this->assertSame([4, 2], [$b['af'], $b['ec']]);

        $c = $rows[2];
        $this->assertSame(['e' => 2, 'pts' => 2], ['e' => $c['e'], 'pts' => $c['pts']]);
        $d = $rows[3];
        $this->assertSame(['e' => 1, 'p' => 1, 'pts' => 1], ['e' => $d['e'], 'p' => $d['p'], 'pts' => $d['pts']]);

        // ── Cache e invalidación (REQ-11): editar un marcador cambia la tabla ──
        // El GET anterior llenó la caché; el segundo GET usa la misma (mismo resultado).
        [$status2, $body2] = $this->dispatchGet("/api/tournaments/{$tournamentId}/standings");
        $this->assertSame(200, $status2);
        $this->assertSame($rows[0]['pts'], $body2['data']['standings'][0]['pts'], 'Cache: mismo resultado en la ventana');

        // Beta vs Delta se invierte (3-0 → 0-5): Delta lidera con pts4/af6,
        // Alpha (pts4/af2) baja al segundo, Beta cae al último.
        $matchId = $this->matchIdDe($tournamentId, $p[1], $p[3]);
        [$status, $body] = $this->dispatchJson('PUT', "/api/tournaments/{$tournamentId}/matches/{$matchId}", [
            'status' => 'completed',
            'scores' => [['stat_id' => $statId, 'a' => 0, 'b' => 5]],
            'winner_participant_id' => $p[3],
        ], $token);
        $this->assertSame(200, $status, 'Marcador editado');

        [$status, $body] = $this->dispatchGet("/api/tournaments/{$tournamentId}/standings");
        $this->assertSame(200, $status);
        $rows = $body['data']['standings'];
        $this->assertSame('Delta FC', $rows[0]['name'], 'Tras la cache inválida, Delta lidera');
        $this->assertSame('Alpha FC', $rows[1]['name'], 'Alpha segundo');
        $this->assertSame('Beta FC', $rows[3]['name'], 'Beta cae al final');

        // ── 404 para torneo inexistente ──
        [$status] = $this->dispatchGet('/api/tournaments/999999/standings');
        $this->assertSame(404, $status);
    }

    private function matchIdDe(int $tournamentId, int $a, int $b): int
    {
        $stmt = self::$pdo->prepare('SELECT id FROM matches WHERE tournament_id = ? AND participant_a_id = ? AND participant_b_id = ?');
        $stmt->execute([$tournamentId, $a, $b]);
        return (int) $stmt->fetchColumn();
    }
}