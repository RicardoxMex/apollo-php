<?php

namespace Tests\Feature;

use Apollo\Core\Database\Connection\DatabaseManager;
use Apollo\Core\Http\Request;
use Tests\TestCase;
use PDO;

/**
 * Seguridad del listado de torneos (APISEC-01, D-F0-4): el anónimo solo ve
 * torneos públicos en estados públicos y nunca soft-deleted; organizer_id
 * exige sesión y `me` se resuelve al actor (403 para otros ids); perPage se
 * acota a 1..100. SQLite :memory: con migraciones reales.
 */
class TournamentListingSecurityTest extends TestCase
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

        // Simula $_GET (el constructor de Request en tests recibe el query aparte).
        $query = [];
        $queryString = parse_url($uri, PHP_URL_QUERY);
        if (is_string($queryString)) {
            parse_str($queryString, $query);
        }

        $response = self::$app->handle(new Request($query, [], [], [], [], $server, json_encode($body)));
        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)];
    }

    /** Registra+verifica+loguea un organizador y devuelve [token, id]. */
    private function organizador(string $username, string $email): array
    {
        [$status, $body] = $this->dispatchJson('POST', '/api/auth/register', [
            'username' => $username,
            'email' => $email,
            'password' => 'clave-listing-1',
        ]);
        $this->assertSame(201, $status);
        $this->assertArrayHasKey('email_verified', $body['data']['user'], 'register expone email_verified');
        $this->assertFalse($body['data']['user']['email_verified'], 'Recién registrado → sin verificar');

        // Verificación directa (el flujo de token ya está cubierto por EmailE2EFlowTest).
        self::$pdo->prepare('UPDATE users SET email_verified_at = ? WHERE email = ?')
            ->execute([date('Y-m-d H:i:s'), $email]);

        [$status, $body] = $this->dispatchJson('POST', '/api/auth/login', [
            'email' => $email,
            'password' => 'clave-listing-1',
        ]);
        $this->assertSame(200, $status);
        $this->assertTrue($body['data']['user']['email_verified'], 'login expone email_verified');
        return [$body['data']['token'], (int) $body['data']['user']['id']];
    }

    private function crearTorneo(string $token, string $titulo, string $visibility = 'publico'): int
    {
        [$status, $body] = $this->dispatchJson('POST', '/api/tournaments', [
            'title' => $titulo,
            'format' => 'round-robin',
            'max_participants' => 8,
            'visibility' => $visibility,
        ], $token);
        $this->assertSame(201, $status);
        return (int) $body['data']['id'];
    }

    public function test_listado_anonimo_excluye_borradores_privados_y_borrados(): void
    {
        // Purga el bucket de rate limit: los flujos de test acumulan registros
        // legítimos en la misma ventana (mismo patrón que EmailE2EFlowTest).
        self::$pdo->prepare('DELETE FROM rate_limits')->execute();

        [$token] = $this->organizador('org_listing', 'org.listing@test.local');

        $borradorPublico = $this->crearTorneo($token, 'Borrador público');
        $privado = $this->crearTorneo($token, 'Privado', 'privado');
        $borrado = $this->crearTorneo($token, 'Borrado');
        $abierto = $this->crearTorneo($token, 'Abierto');

        [$status] = $this->dispatchJson('POST', "/api/tournaments/{$abierto}/publish", [], $token);
        $this->assertSame(200, $status, 'Publicar (email verificado)');
        [$status] = $this->dispatchJson('DELETE', "/api/tournaments/{$borrado}", [], $token);
        $this->assertSame(200, $status, 'Soft-delete');

        // Listado anónimo: solo el público abierto.
        [$status, $body] = $this->dispatchJson('GET', '/api/tournaments?perPage=50');
        $this->assertSame(200, $status);
        $ids = array_map(fn($t) => (int) $t['id'], $body['data']);
        $this->assertContains($abierto, $ids, 'El público abierto se lista');
        $this->assertNotContains($borradorPublico, $ids, 'El borrador no se lista');
        $this->assertNotContains($privado, $ids, 'El privado no se lista');
        $this->assertNotContains($borrado, $ids, 'El borrado no se lista');

        // Status no público pedido por el anónimo → vacío, nunca borradores.
        [$status, $body] = $this->dispatchJson('GET', '/api/tournaments?status=draft');
        $this->assertSame(200, $status);
        $this->assertCount(0, $body['data'], 'status=draft anónimo → vacío');

        // visibility=privado pedido por el anónimo: se fuerza público, así que
        // jamás devuelve el privado (solo aparece el público abierto).
        [$status, $body] = $this->dispatchJson('GET', '/api/tournaments?visibility=privado');
        $this->assertSame(200, $status);
        $ids = array_map(fn($t) => (int) $t['id'], $body['data']);
        $this->assertNotContains($privado, $ids, 'El anónimo nunca ve privados');
        $this->assertSame([$abierto], $ids, 'visibility=privado anónimo se fuerza a público');

        // Filtro de estado público permitido.
        [$status, $body] = $this->dispatchJson('GET', '/api/tournaments?status=open');
        $this->assertSame(200, $status);
        $this->assertSame([$abierto], array_map(fn($t) => (int) $t['id'], $body['data']));
    }

    public function test_organizer_id_requiere_sesion_y_me_resuelve_al_actor(): void
    {
        self::$pdo->prepare('DELETE FROM rate_limits')->execute();

        [$token, $actorId] = $this->organizador('org_listing_2', 'org.listing2@test.local');
        [$otroToken, $otroId] = $this->organizador('org_listing_3', 'org.listing3@test.local');

        $borrador = $this->crearTorneo($token, 'Copa de mis torneos');

        // Sin sesión → 401 (aunque el listado público siga abierto).
        [$status] = $this->dispatchJson('GET', '/api/tournaments?organizer_id=me');
        $this->assertSame(401, $status, 'organizer_id sin sesión → 401');

        // Con sesión, `me` resuelve al actor y sus borradores aparecen.
        [$status, $body] = $this->dispatchJson('GET', '/api/tournaments?organizer_id=me&perPage=200', [], $token);
        $this->assertSame(200, $status);
        $ids = array_map(fn($t) => (int) $t['id'], $body['data']);
        $this->assertContains($borrador, $ids, 'El organizador ve su borrador con organizer_id=me');
        $this->assertSame($actorId, (int) $body['data'][0]['organizer_id']);

        // Id numérico propio → permitido.
        [$status] = $this->dispatchJson('GET', "/api/tournaments?organizer_id={$actorId}", [], $token);
        $this->assertSame(200, $status);

        // Otro organizador no admin → 403 (con y sin `me`).
        [$status] = $this->dispatchJson('GET', "/api/tournaments?organizer_id={$otroId}", [], $token);
        $this->assertSame(403, $status, 'Ver torneos de otro organizador → 403');

        [$status, $body] = $this->dispatchJson('GET', '/api/tournaments?organizer_id=me&perPage=200', [], $otroToken);
        $this->assertSame(200, $status);
        $idsOtro = array_map(fn($t) => (int) $t['id'], $body['data']);
        $this->assertNotContains($borrador, $idsOtro, 'Cada organizador ve solo lo suyo');
    }

    public function test_per_page_se_acota_a_100(): void
    {
        [$status, $body] = $this->dispatchJson('GET', '/api/tournaments?perPage=1000');
        $this->assertSame(200, $status);
        $this->assertSame(100, (int) $body['meta']['per_page'], 'perPage=1000 → 100');

        [$status, $body] = $this->dispatchJson('GET', '/api/tournaments?perPage=0');
        $this->assertSame(200, $status);
        $this->assertSame(1, (int) $body['meta']['per_page'], 'perPage=0 → 1');
    }
}
