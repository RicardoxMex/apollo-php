<?php

namespace Tests\Feature;

use Apollo\Core\Database\Connection\DatabaseManager;
use Apollo\Core\Http\Request;
use Apollo\Core\Http\Response;
use Apollo\Core\Uploads\Exceptions\UploadException;
use Apollo\Core\Uploads\Support\UploadManager;
use Tests\TestCase;
use PDO;

/**
 * Perímetro de uploads y ownership de escrituras (R-PERIM-02):
 * whitelist de imágenes por defecto, nosniff en respuestas de archivo,
 * teams/players con ownership (capitán/dueño/admin) y seasons solo admin.
 * SQLite :memory: con migraciones reales.
 */
class UploadsOwnershipTest extends TestCase
{
    private static PDO $pdo;
    private static ?string $adminToken = null;
    private string $tmpDir;

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

        require_once dirname(__DIR__, 2) . '/database/seeds/RolesSeeder.php';
        ob_start();
        (new \RolesSeeder())->run();
        ob_end_clean();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // El rate limit de auth no es objeto de este test: ventana limpia por test
        $this->purgeRateLimits();

        $this->tmpDir = sys_get_temp_dir() . '/apollo-perim2-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);

        parent::tearDown();
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

    /**
     * El rate limit de auth no es objeto de este test: ventana limpia antes de
     * cada alta/login para que el 429 no oculte el comportamiento de ownership.
     */
    private function purgeRateLimits(): void
    {
        self::$pdo->exec('DELETE FROM rate_limits');
    }

    /**
     * Registra y loguea un usuario; devuelve [token, id].
     */
    private function usuario(string $username, string $email): array
    {
        $this->purgeRateLimits();

        [$status] = $this->dispatchJson('POST', '/api/auth/register', [
            'username' => $username,
            'email' => $email,
            'password' => 'clave-perim2-1',
        ]);
        $this->assertSame(201, $status, "Registro {$email}");

        [$status, $body] = $this->dispatchJson('POST', '/api/auth/login', [
            'email' => $email,
            'password' => 'clave-perim2-1',
        ]);
        $this->assertSame(200, $status, "Login {$email}");

        $stmt = self::$pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);

        return [$body['data']['token'], (int) $stmt->fetchColumn()];
    }

    private function adminToken(): string
    {
        if (self::$adminToken === null) {
            $this->purgeRateLimits();

            [$status, $body] = $this->dispatchJson('POST', '/api/auth/login', [
                'email' => 'admin@apollo.local',
                'password' => 'admin123',
            ]);
            $this->assertSame(200, $status, 'Login admin del seeder');
            self::$adminToken = $body['data']['token'];
        }

        return self::$adminToken;
    }

    private function tmpFile(string $name, string $content): string
    {
        $path = $this->tmpDir . '/' . $name;
        file_put_contents($path, $content);

        return $path;
    }

    private function uploadEntry(string $name, string $path): array
    {
        return [
            'name' => $name,
            'type' => 'application/octet-stream',
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($path),
        ];
    }

    /**
     * Inscripción directa en tournament_registrations (setup de ownership).
     */
    private function insertRegistration(int $tournamentId, int $applicantId, ?int $teamId = null, ?int $playerId = null, string $status = 'accepted'): int
    {
        $now = date('Y-m-d H:i:s');

        self::$pdo->prepare(
            'INSERT INTO tournament_registrations (tournament_id, team_id, player_id, applicant_id, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$tournamentId, $teamId, $playerId, $applicantId, $status, $now, $now]);

        return (int) self::$pdo->lastInsertId();
    }

    /**
     * Fila de participante directa (setup del branch participants).
     */
    private function insertParticipant(int $tournamentId, int $registrationId, ?int $teamId = null, ?int $playerId = null): void
    {
        self::$pdo->prepare(
            'INSERT INTO tournament_participants (tournament_id, registration_id, team_id, player_id, seed, created_at)
             VALUES (?, ?, ?, ?, NULL, ?)'
        )->execute([$tournamentId, $registrationId, $teamId, $playerId, date('Y-m-d H:i:s')]);
    }

    public function test_default_whitelist_rejects_non_image(): void
    {
        $config = config('uploads');

        // La whitelist por defecto aplica aunque no haya env
        $this->assertNotEmpty($config['allowed_mimes'] ?? null);
        $allowed = array_map('trim', explode(',', (string) $config['allowed_mimes']));
        foreach (['jpeg', 'jpg', 'png', 'webp', 'gif'] as $extension) {
            $this->assertContains($extension, $allowed, "Imagen permitida: {$extension}");
        }
        $this->assertNotContains('svg', $allowed, 'svg fuera de la whitelist');

        // Manager con la config real del proyecto y root de test
        $config['root'] = $this->tmpDir;
        $manager = new UploadManager($config);

        $txt = $this->tmpFile('notas.txt', 'solo texto');
        try {
            $manager->store($this->uploadEntry('notas.txt', $txt), 'teams');
            $this->fail('Un .txt debe ser rechazado por la whitelist por defecto');
        } catch (UploadException $e) {
            $this->assertStringContainsString('no permitido', $e->getMessage());
        }

        $svg = $this->tmpFile('vector.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
        try {
            $manager->store($this->uploadEntry('vector.svg', $svg), 'teams');
            $this->fail('Un .svg debe ser rechazado por la whitelist por defecto');
        } catch (UploadException $e) {
            $this->assertStringContainsString('no permitido', $e->getMessage());
        }
    }

    public function test_file_response_includes_nosniff(): void
    {
        $path = $this->tmpFile('escudo.png', 'contenido-png');

        $response = Response::file($path);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('nosniff', $response->getHeaders()['X-Content-Type-Options'] ?? null);
    }

    public function test_team_update_requires_captain_or_admin(): void
    {
        [$capToken] = $this->usuario('cap_team_own', 'cap.team.own@test.local');
        [$otherToken] = $this->usuario('other_team_own', 'other.team.own@test.local');

        [$status, $body] = $this->dispatchJson('POST', '/api/teams', ['name' => 'Ownership FC'], $capToken);
        $this->assertSame(201, $status);
        $teamId = $body['data']['id'];

        // No-capitán → 403
        [$status] = $this->dispatchJson('PUT', "/api/teams/{$teamId}", ['name' => 'Hackeado'], $otherToken);
        $this->assertSame(403, $status, 'No-capitán no actualiza el equipo');

        // Capitán → 200
        [$status, $body] = $this->dispatchJson('PUT', "/api/teams/{$teamId}", ['name' => 'Ownership FC 2'], $capToken);
        $this->assertSame(200, $status, 'Capitán actualiza el equipo');
        $this->assertSame('Ownership FC 2', $body['data']['name']);

        // Admin → 200 (override)
        [$status, $body] = $this->dispatchJson('PUT', "/api/teams/{$teamId}", ['name' => 'Admin FC'], $this->adminToken());
        $this->assertSame(200, $status, 'Admin actualiza cualquier equipo');
        $this->assertSame('Admin FC', $body['data']['name']);
    }

    public function test_team_delete_requires_captain(): void
    {
        [$capToken] = $this->usuario('cap_team_del', 'cap.team.del@test.local');
        [$otherToken] = $this->usuario('other_team_del', 'other.team.del@test.local');

        [$status, $body] = $this->dispatchJson('POST', '/api/teams', ['name' => 'Borrable FC'], $capToken);
        $this->assertSame(201, $status);
        $teamId = $body['data']['id'];

        [$status] = $this->dispatchJson('DELETE', "/api/teams/{$teamId}", [], $otherToken);
        $this->assertSame(403, $status, 'No-capitán no elimina el equipo');

        [$status] = $this->dispatchJson('DELETE', "/api/teams/{$teamId}", [], $capToken);
        $this->assertSame(200, $status, 'Capitán elimina el equipo');
    }

    public function test_player_update_requires_owner_captain_or_admin(): void
    {
        [$ownerToken, $ownerId] = $this->usuario('owner_player', 'owner.player@test.local');
        [$otherToken] = $this->usuario('other_player', 'other.player@test.local');

        // Dueño (user_id) crea su jugador
        [$status, $body] = $this->dispatchJson('POST', '/api/players', [
            'name' => 'Jugador Dueño',
            'user_id' => $ownerId,
        ], $ownerToken);
        $this->assertSame(201, $status);
        $playerId = $body['data']['id'];

        // Tercero → 403
        [$status] = $this->dispatchJson('PUT', "/api/players/{$playerId}", ['name' => 'Ajeno'], $otherToken);
        $this->assertSame(403, $status, 'Tercero no actualiza al jugador');

        // Dueño → 200
        [$status, $body] = $this->dispatchJson('PUT', "/api/players/{$playerId}", ['name' => 'Jugador Dueño 2'], $ownerToken);
        $this->assertSame(200, $status, 'Dueño actualiza al jugador');
        $this->assertSame('Jugador Dueño 2', $body['data']['name']);

        // Jugador sin user_id: el creador no es dueño → 403
        [$status, $body] = $this->dispatchJson('POST', '/api/players', ['name' => 'Jugador Capitán'], $otherToken);
        $this->assertSame(201, $status);
        $player2Id = $body['data']['id'];

        [$status] = $this->dispatchJson('PUT', "/api/players/{$player2Id}", ['name' => 'Sin permiso'], $otherToken);
        $this->assertSame(403, $status, 'El creador sin user_id no es dueño');

        // Capitán de un equipo que contiene al jugador → 200
        [$status, $body] = $this->dispatchJson('POST', '/api/teams', ['name' => 'Equipo Capitán'], $ownerToken);
        $this->assertSame(201, $status);
        $teamId = $body['data']['id'];

        self::$pdo->prepare('INSERT INTO team_players (team_id, player_id, jersey_number, joined_at) VALUES (?, ?, ?, ?)')
            ->execute([$teamId, $player2Id, null, date('Y-m-d H:i:s')]);

        [$status] = $this->dispatchJson('PUT', "/api/players/{$player2Id}", ['name' => 'Jugador Capitán 2'], $ownerToken);
        $this->assertSame(200, $status, 'Capitán actualiza a un jugador de su equipo');

        // Admin → 200
        [$status] = $this->dispatchJson('PUT', "/api/players/{$player2Id}", ['name' => 'Jugador Admin'], $this->adminToken());
        $this->assertSame(200, $status, 'Admin actualiza cualquier jugador');
    }

    public function test_player_delete_requires_ownership(): void
    {
        [$ownerToken, $ownerId] = $this->usuario('owner_player_del', 'owner.player.del@test.local');
        [$otherToken] = $this->usuario('other_player_del', 'other.player.del@test.local');

        [$status, $body] = $this->dispatchJson('POST', '/api/players', [
            'name' => 'P Borrable',
            'user_id' => $ownerId,
        ], $ownerToken);
        $this->assertSame(201, $status);
        $playerId = $body['data']['id'];

        [$status] = $this->dispatchJson('DELETE', "/api/players/{$playerId}", [], $otherToken);
        $this->assertSame(403, $status, 'No-dueño no elimina al jugador');

        [$status] = $this->dispatchJson('DELETE', "/api/players/{$playerId}", [], $ownerToken);
        $this->assertSame(200, $status, 'Dueño elimina al jugador (sin torneos)');
    }

    public function test_season_writes_require_admin(): void
    {
        [$userToken] = $this->usuario('season_user', 'season.user@test.local');

        // No-admin: writes → 403; GET mantiene auth
        [$status] = $this->dispatchJson('POST', '/api/seasons', ['name' => 'Temporada X'], $userToken);
        $this->assertSame(403, $status, 'No-admin no crea temporadas');

        [$status, $body] = $this->dispatchJson('GET', '/api/seasons', [], $userToken);
        $this->assertSame(200, $status, 'GET temporadas con auth');

        // Admin: POST → 201
        [$status, $body] = $this->dispatchJson('POST', '/api/seasons', ['name' => 'Temporada Admin'], $this->adminToken());
        $this->assertSame(201, $status, 'Admin crea temporadas');
        $seasonId = $body['data']['id'];

        // No-admin: update/delete → 403
        [$status] = $this->dispatchJson('PUT', "/api/seasons/{$seasonId}", ['name' => 'Hack'], $userToken);
        $this->assertSame(403, $status, 'No-admin no actualiza temporadas');

        [$status] = $this->dispatchJson('DELETE', "/api/seasons/{$seasonId}", [], $userToken);
        $this->assertSame(403, $status, 'No-admin no elimina temporadas');

        // Anónimo → 401
        [$status] = $this->dispatchJson('POST', '/api/seasons', ['name' => 'Nada']);
        $this->assertSame(401, $status, 'Anónimo no crea temporadas');

        // Admin: update/delete → 200
        [$status] = $this->dispatchJson('PUT', "/api/seasons/{$seasonId}", ['name' => 'Temporada Admin 2'], $this->adminToken());
        $this->assertSame(200, $status, 'Admin actualiza temporadas');

        [$status] = $this->dispatchJson('DELETE', "/api/seasons/{$seasonId}", [], $this->adminToken());
        $this->assertSame(200, $status, 'Admin elimina temporadas');
    }

    public function test_organizer_can_manage_team_of_their_tournament(): void
    {
        [$orgToken] = $this->usuario('org_team_torneo', 'org.team.torneo@test.local');
        [$applicantToken, $applicantId] = $this->usuario('app_team_torneo', 'app.team.torneo@test.local');
        [$unrelatedToken] = $this->usuario('unrel_team_torneo', 'unrel.team.torneo@test.local');

        // Torneo del organizador (draft; no requiere email verificado)
        [$status, $body] = $this->dispatchJson('POST', '/api/tournaments', [
            'title' => 'Copa Ownership Equipos',
            'format' => 'round-robin',
            'max_participants' => 8,
            'visibility' => 'publico',
        ], $orgToken);
        $this->assertSame(201, $status, 'Torneo creado');
        $tournamentId = (int) $body['data']['id'];

        // Equipo del solicitante: el organizador NO es capitán
        [$status, $body] = $this->dispatchJson('POST', '/api/teams', ['name' => 'Equipo Solicitante'], $applicantToken);
        $this->assertSame(201, $status);
        $teamId = (int) $body['data']['id'];

        // Sin relación con el torneo → 403
        [$status] = $this->dispatchJson('PUT', "/api/teams/{$teamId}", ['name' => 'Antes'], $orgToken);
        $this->assertSame(403, $status, 'Sin inscripción no hay ownership de organizador');

        // Inscripción (branch tournament_registrations.team_id)
        $registrationId = $this->insertRegistration($tournamentId, $applicantId, $teamId);

        // Tercero ajeno → 403
        [$status] = $this->dispatchJson('PUT', "/api/teams/{$teamId}", ['name' => 'Ajeno'], $unrelatedToken);
        $this->assertSame(403, $status, 'Usuario ajeno no gestiona el equipo');

        // Organizador → 200
        [$status, $body] = $this->dispatchJson('PUT', "/api/teams/{$teamId}", ['name' => 'Equipo Gestionado'], $orgToken);
        $this->assertSame(200, $status, 'Organizador edita equipo inscrito en su torneo');
        $this->assertSame('Equipo Gestionado', $body['data']['name']);

        // Branch tournament_participants.team_id (participante sin inscripción propia)
        [$status, $body] = $this->dispatchJson('POST', '/api/teams', ['name' => 'Equipo Participante'], $applicantToken);
        $this->assertSame(201, $status);
        $teamBId = (int) $body['data']['id'];
        $this->insertParticipant($tournamentId, $registrationId, $teamBId);

        [$status] = $this->dispatchJson('PUT', "/api/teams/{$teamBId}", ['name' => 'Equipo Participante 2'], $orgToken);
        $this->assertSame(200, $status, 'Organizador edita equipo participante en su torneo');

        // Organizador → DELETE (soft delete)
        [$status] = $this->dispatchJson('DELETE', "/api/teams/{$teamId}", [], $orgToken);
        $this->assertSame(200, $status, 'Organizador elimina equipo inscrito en su torneo');
    }

    public function test_organizer_can_manage_player_of_their_tournament(): void
    {
        [$orgToken] = $this->usuario('org_player_torneo', 'org.player.torneo@test.local');
        [$applicantToken, $applicantId] = $this->usuario('app_player_torneo', 'app.player.torneo@test.local');
        [$unrelatedToken] = $this->usuario('unrel_player_torneo', 'unrel.player.torneo@test.local');

        // Torneo del organizador
        [$status, $body] = $this->dispatchJson('POST', '/api/tournaments', [
            'title' => 'Copa Ownership Jugadores',
            'format' => 'round-robin',
            'max_participants' => 8,
            'visibility' => 'publico',
        ], $orgToken);
        $this->assertSame(201, $status, 'Torneo creado');
        $tournamentId = (int) $body['data']['id'];

        // Jugador del solicitante (sin user_id: nadie es dueño)
        [$status, $body] = $this->dispatchJson('POST', '/api/players', ['name' => 'Jugador Inscrito'], $applicantToken);
        $this->assertSame(201, $status);
        $playerId = (int) $body['data']['id'];

        // Sin relación con el torneo → 403
        [$status] = $this->dispatchJson('PUT', "/api/players/{$playerId}", ['name' => 'Antes'], $orgToken);
        $this->assertSame(403, $status, 'Sin inscripción no hay ownership de organizador');

        // Inscripción (branch tournament_registrations.player_id)
        $registrationId = $this->insertRegistration($tournamentId, $applicantId, null, $playerId);

        // Tercero ajeno → 403
        [$status] = $this->dispatchJson('PUT', "/api/players/{$playerId}", ['name' => 'Ajeno'], $unrelatedToken);
        $this->assertSame(403, $status, 'Usuario ajeno no gestiona al jugador');

        // Organizador → 200
        [$status, $body] = $this->dispatchJson('PUT', "/api/players/{$playerId}", ['name' => 'Jugador Gestionado'], $orgToken);
        $this->assertSame(200, $status, 'Organizador edita jugador inscrito en su torneo');
        $this->assertSame('Jugador Gestionado', $body['data']['name']);

        // Branch tournament_participants.player_id
        [$status, $body] = $this->dispatchJson('POST', '/api/players', ['name' => 'Jugador Participante'], $applicantToken);
        $this->assertSame(201, $status);
        $playerBId = (int) $body['data']['id'];
        $this->insertParticipant($tournamentId, $registrationId, null, $playerBId);

        [$status] = $this->dispatchJson('PUT', "/api/players/{$playerBId}", ['name' => 'Jugador Participante 2'], $orgToken);
        $this->assertSame(200, $status, 'Organizador edita jugador participante en su torneo');

        // DELETE: tercero ajeno → 403. El organizador queda autorizado; su
        // DELETE responde 500 por el bug conocido de FK (jugador inscrito en
        // un torneo), fuera de alcance de PERIM-02, por eso solo se verifica
        // que la autorización no lo bloquea con 403.
        [$status] = $this->dispatchJson('DELETE', "/api/players/{$playerId}", [], $unrelatedToken);
        $this->assertSame(403, $status, 'Usuario ajeno no elimina al jugador');

        [$status] = $this->dispatchJson('DELETE', "/api/players/{$playerId}", [], $orgToken);
        $this->assertNotSame(403, $status, 'Organizador autorizado a eliminar al jugador');
    }
}
