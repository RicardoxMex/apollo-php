<?php

namespace Tests\Feature;

use Apollo\Core\Database\Connection\DatabaseManager;
use Apollo\Core\Http\Request;
use Tests\TestCase;
use PDO;

/**
 * Inscripciones del participante (PORTAL-01, REQ-01 / AC-01):
 * GET /profile/registrations actor-scoped con torneo, participante, estado y
 * resumen de pago agregado. SQLite :memory: con migraciones reales y kernel
 * real in-process (auth JWT real).
 */
class ProfileRegistrationsTest extends TestCase
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

    private function registrarYloguear(string $username, string $email): string
    {
        // Purga el bucket de rate limit (mismo patrón que AuthProfileUpdateTest;
        // el 429 real lo cubre RateLimitRoutesTest).
        self::$pdo->prepare('DELETE FROM rate_limits')->execute();

        [$status] = $this->dispatchJson('POST', '/api/auth/register', [
            'username' => $username,
            'email' => $email,
            'password' => 'clave-portal-1',
        ]);
        $this->assertSame(201, $status);

        [$status, $body] = $this->dispatchJson('POST', '/api/auth/login', [
            'email' => $email,
            'password' => 'clave-portal-1',
        ]);
        $this->assertSame(200, $status);
        return $body['data']['token'];
    }

    private function userId(string $email): int
    {
        $stmt = self::$pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        return (int) $stmt->fetchColumn();
    }

    private function createTournament(int $organizerId, array $data = []): int
    {
        $now = date('Y-m-d H:i:s');
        self::$pdo->prepare(
            "INSERT INTO tournaments (organizer_id, title, slug, sport, status, format, max_participants,
                                      visibility, registration_fee, currency, start_date, created_at, updated_at)
             VALUES (?, ?, ?, 'Fútbol', 'open', 'single_elimination', 8, 'public', ?, ?, ?, ?, ?)"
        )->execute([
            $organizerId,
            $data['title'] ?? 'Copa Portal',
            'copa-portal-' . bin2hex(random_bytes(4)),
            $data['registration_fee'] ?? 0,
            $data['currency'] ?? 'USD',
            $data['start_date'] ?? '2026-10-01 09:00:00',
            $now,
            $now,
        ]);
        return (int) self::$pdo->lastInsertId();
    }

    private function createTeam(string $name): int
    {
        $now = date('Y-m-d H:i:s');
        self::$pdo->prepare('INSERT INTO teams (name, created_at, updated_at) VALUES (?, ?, ?)')
            ->execute([$name, $now, $now]);
        return (int) self::$pdo->lastInsertId();
    }

    private function createPlayer(?int $userId, string $name): int
    {
        $now = date('Y-m-d H:i:s');
        self::$pdo->prepare('INSERT INTO players (user_id, name, created_at, updated_at) VALUES (?, ?, ?, ?)')
            ->execute([$userId, $name, $now, $now]);
        return (int) self::$pdo->lastInsertId();
    }

    private function createRegistration(int $tournamentId, int $applicantId, array $data = []): int
    {
        $created = $data['created_at'] ?? date('Y-m-d H:i:s');
        self::$pdo->prepare(
            'INSERT INTO tournament_registrations
                (tournament_id, team_id, player_id, applicant_id, status, message, decided_by, decided_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $tournamentId,
            $data['team_id'] ?? null,
            $data['player_id'] ?? null,
            $applicantId,
            $data['status'] ?? 'pending',
            $data['message'] ?? 'mensaje interno del solicitante',
            $data['decided_by'] ?? null,
            $data['decided_at'] ?? null,
            $created,
            $created,
        ]);
        return (int) self::$pdo->lastInsertId();
    }

    private function createPayment(int $tournamentId, int $registrationId, float $amount, string $status, int $recordedBy): void
    {
        $now = date('Y-m-d H:i:s');
        self::$pdo->prepare(
            "INSERT INTO payments (tournament_id, registration_id, amount, currency, method, status, paid_at, recorded_by, notes, created_at, updated_at)
             VALUES (?, ?, ?, 'USD', 'efectivo', ?, ?, ?, 'nota interna del organizador', ?, ?)"
        )->execute([$tournamentId, $registrationId, $amount, $status, $status === 'paid' ? $now : null, $recordedBy, $now, $now]);
    }

    private function assertNoPrivateKeys(array $data, string $path = 'data'): void
    {
        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $this->assertNotContains($key, ['notes', 'recorded_by', 'decided_by'], "La clave {$path}.{$key} no debe exponerse");
            }
            if (is_array($value)) {
                $this->assertNoPrivateKeys($value, "{$path}.{$key}");
            }
        }
    }

    public function test_requires_auth(): void
    {
        [$status] = $this->dispatchJson('GET', '/api/profile/registrations');
        $this->assertSame(401, $status);
    }

    public function test_actor_scoped_only_own_registrations(): void
    {
        $tokenA = $this->registrarYloguear('portal_a', 'portal.a@test.local');
        $tokenB = $this->registrarYloguear('portal_b', 'portal.b@test.local');
        $userA = $this->userId('portal.a@test.local');
        $userB = $this->userId('portal.b@test.local');

        $tournamentA = $this->createTournament($userA, ['title' => 'Copa Solo A']);
        $tournamentB = $this->createTournament($userB, ['title' => 'Copa Solo B']);
        $regA = $this->createRegistration($tournamentA, $userA, ['team_id' => $this->createTeam('Equipo A')]);
        $regB = $this->createRegistration($tournamentB, $userB, ['team_id' => $this->createTeam('Equipo B')]);

        [$status, $body] = $this->dispatchJson('GET', '/api/profile/registrations', [], $tokenA);
        $this->assertSame(200, $status);
        $this->assertCount(1, $body['data'], 'A solo ve su inscripción');
        $this->assertSame($regA, $body['data'][0]['id']);
        $this->assertSame('Copa Solo A', $body['data'][0]['tournament']['title']);

        [$status, $body] = $this->dispatchJson('GET', '/api/profile/registrations', [], $tokenB);
        $this->assertSame(200, $status);
        $this->assertCount(1, $body['data'], 'B solo ve su inscripción');
        $this->assertSame($regB, $body['data'][0]['id']);
        $this->assertSame('Copa Solo B', $body['data'][0]['tournament']['title']);
    }

    public function test_linked_player_sees_registration_even_if_not_applicant(): void
    {
        $tokenApplicant = $this->registrarYloguear('portal_jug1', 'portal.jug1@test.local');
        $tokenPlayer = $this->registrarYloguear('portal_jug2', 'portal.jug2@test.local');
        $tokenTercero = $this->registrarYloguear('portal_jug3', 'portal.jug3@test.local');
        $applicant = $this->userId('portal.jug1@test.local');
        $playerUser = $this->userId('portal.jug2@test.local');

        $tournament = $this->createTournament($applicant, ['title' => 'Copa Individual']);
        $playerId = $this->createPlayer($playerUser, 'Juan Pérez');
        $registrationId = $this->createRegistration($tournament, $applicant, ['player_id' => $playerId]);

        // El jugador vinculado (players.user_id) ve la inscripción aunque no la solicitó.
        [$status, $body] = $this->dispatchJson('GET', '/api/profile/registrations', [], $tokenPlayer);
        $this->assertSame(200, $status);
        $this->assertCount(1, $body['data']);
        $this->assertSame($registrationId, $body['data'][0]['id']);
        $this->assertSame($playerId, $body['data'][0]['participant']['player_id']);
        $this->assertSame('Juan Pérez', $body['data'][0]['participant']['player_name']);
        $this->assertNull($body['data'][0]['participant']['team_id']);
        $this->assertNull($body['data'][0]['participant']['team_name']);

        // El solicitante también la ve (applicant_id).
        [$status, $body] = $this->dispatchJson('GET', '/api/profile/registrations', [], $tokenApplicant);
        $this->assertSame(200, $status);
        $this->assertCount(1, $body['data']);

        // Un tercer usuario no ve nada ajeno.
        [$status, $body] = $this->dispatchJson('GET', '/api/profile/registrations', [], $tokenTercero);
        $this->assertSame(200, $status);
        $this->assertSame([], $body['data']);
    }

    public function test_payment_aggregation_states(): void
    {
        $token = $this->registrarYloguear('portal_pago', 'portal.pago@test.local');
        $user = $this->userId('portal.pago@test.local');

        // Sin cuota → none.
        $t1 = $this->createTournament($user, ['title' => 'Copa Sin Cuota', 'registration_fee' => 0]);
        $r1 = $this->createRegistration($t1, $user, ['team_id' => $this->createTeam('E1'), 'created_at' => '2026-09-01 10:00:00']);

        // Cuota > 0 sin pagos → pending con la cuota completa pendiente.
        $t2 = $this->createTournament($user, ['title' => 'Copa Pendiente', 'registration_fee' => 150]);
        $r2 = $this->createRegistration($t2, $user, ['team_id' => $this->createTeam('E2'), 'created_at' => '2026-09-02 10:00:00']);

        // Pago parcial (y un pago 'pending' que NO cuenta) → pending.
        $t3 = $this->createTournament($user, ['title' => 'Copa Parcial', 'registration_fee' => 150]);
        $r3 = $this->createRegistration($t3, $user, ['team_id' => $this->createTeam('E3'), 'created_at' => '2026-09-03 10:00:00']);
        $this->createPayment($t3, $r3, 50.0, 'paid', $user);
        $this->createPayment($t3, $r3, 100.0, 'pending', $user);

        // Pago completo → paid.
        $t4 = $this->createTournament($user, ['title' => 'Copa Pagada', 'registration_fee' => 150]);
        $r4 = $this->createRegistration($t4, $user, ['team_id' => $this->createTeam('E4'), 'created_at' => '2026-09-04 10:00:00']);
        $this->createPayment($t4, $r4, 150.0, 'paid', $user);

        // Pago de más → paid y amount_due nunca negativo.
        $t5 = $this->createTournament($user, ['title' => 'Copa De Más', 'registration_fee' => 150]);
        $r5 = $this->createRegistration($t5, $user, ['team_id' => $this->createTeam('E5'), 'created_at' => '2026-09-05 10:00:00']);
        $this->createPayment($t5, $r5, 200.0, 'paid', $user);

        [$status, $body] = $this->dispatchJson('GET', '/api/profile/registrations', [], $token);
        $this->assertSame(200, $status);
        $this->assertSame([$r5, $r4, $r3, $r2, $r1], array_column($body['data'], 'id'), 'Más recientes primero');

        $byId = [];
        foreach ($body['data'] as $item) {
            $byId[$item['id']] = $item;
        }

        $this->assertSame('none', $byId[$r1]['payment']['status']);
        $this->assertSame(0.0, (float) $byId[$r1]['payment']['paid_total']);
        $this->assertSame(0.0, (float) $byId[$r1]['payment']['amount_due']);
        $this->assertSame(0.0, (float) $byId[$r1]['tournament']['registration_fee']);

        $this->assertSame('pending', $byId[$r2]['payment']['status']);
        $this->assertSame(0.0, (float) $byId[$r2]['payment']['paid_total']);
        $this->assertSame(150.0, (float) $byId[$r2]['payment']['amount_due']);
        $this->assertSame(150.0, (float) $byId[$r2]['tournament']['registration_fee']);

        $this->assertSame('pending', $byId[$r3]['payment']['status']);
        $this->assertSame(50.0, (float) $byId[$r3]['payment']['paid_total'], 'Solo los pagos paid suman');
        $this->assertSame(100.0, (float) $byId[$r3]['payment']['amount_due']);

        $this->assertSame('paid', $byId[$r4]['payment']['status']);
        $this->assertSame(150.0, (float) $byId[$r4]['payment']['paid_total']);
        $this->assertSame(0.0, (float) $byId[$r4]['payment']['amount_due']);

        $this->assertSame('paid', $byId[$r5]['payment']['status']);
        $this->assertSame(200.0, (float) $byId[$r5]['payment']['paid_total']);
        $this->assertSame(0.0, (float) $byId[$r5]['payment']['amount_due']);

        $this->assertSame('USD', $byId[$r2]['payment']['currency']);
    }

    public function test_response_exposes_contract_without_private_keys(): void
    {
        $token = $this->registrarYloguear('portal_priv', 'portal.priv@test.local');
        $user = $this->userId('portal.priv@test.local');

        $tournament = $this->createTournament($user, [
            'title' => 'Copa Privacidad',
            'registration_fee' => 100,
            'start_date' => '2026-11-15 08:00:00',
        ]);
        $teamId = $this->createTeam('Privacidad FC');
        $registrationId = $this->createRegistration($tournament, $user, [
            'team_id' => $teamId,
            'status' => 'accepted',
            'decided_by' => $user,
            'decided_at' => '2026-09-10 12:00:00',
            'created_at' => '2026-09-08 09:00:00',
        ]);
        $this->createPayment($tournament, $registrationId, 100.0, 'paid', $user);

        [$status, $body] = $this->dispatchJson('GET', '/api/profile/registrations', [], $token);
        $this->assertSame(200, $status);
        $this->assertTrue($body['success']);
        $this->assertCount(1, $body['data']);

        $item = $body['data'][0];
        $this->assertSame($registrationId, $item['id']);
        $this->assertSame('accepted', $item['status']);
        $this->assertSame('2026-09-08 09:00:00', $item['created_at']);
        $this->assertSame('2026-09-10 12:00:00', $item['decided_at']);

        $this->assertSame($tournament, $item['tournament']['id']);
        $this->assertSame('Copa Privacidad', $item['tournament']['title']);
        $this->assertSame('open', $item['tournament']['status']);
        $this->assertNotEmpty($item['tournament']['slug']);
        $this->assertSame('2026-11-15 08:00:00', $item['tournament']['start_date']);
        $this->assertSame(100.0, (float) $item['tournament']['registration_fee']);
        $this->assertSame('USD', $item['tournament']['currency']);

        $this->assertSame($teamId, $item['participant']['team_id']);
        $this->assertSame('Privacidad FC', $item['participant']['team_name']);
        $this->assertNull($item['participant']['player_id']);
        $this->assertNull($item['participant']['player_name']);

        $this->assertSame('paid', $item['payment']['status']);
        $this->assertSame(100.0, (float) $item['payment']['paid_total']);
        $this->assertSame(0.0, (float) $item['payment']['amount_due']);

        // Privacidad: nunca notes/recorded_by/decided_by (ni en subárboles).
        $this->assertNoPrivateKeys($body);
    }
}
