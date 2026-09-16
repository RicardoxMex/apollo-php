<?php

namespace Tests\Feature;

use Apollo\Core\Database\Connection\DatabaseManager;
use Apollo\Core\Http\Request;
use Tests\TestCase;
use PDO;

/**
 * Paginación y contador de no leídas del listado de notificaciones
 * (NOTIF-01, REQ-06): GET /api/notifications acepta page/perPage (acotado
 * 1..100, por defecto 20), devuelve meta, mantiene el filtro unread=1 y el
 * scoping estricto por usuario autenticado.
 * SQLite :memory: con migraciones reales y kernel real in-process (JWT real).
 */
class NotificationsPaginationTest extends TestCase
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

    /** Registra+loguea un usuario y devuelve [token, id]. */
    private function usuario(string $username, string $email): array
    {
        // Purga el bucket de rate limit (mismo patrón que AuthProfileUpdateTest;
        // el 429 real lo cubre RateLimitRoutesTest).
        self::$pdo->prepare('DELETE FROM rate_limits')->execute();

        [$status] = $this->dispatchJson('POST', '/api/auth/register', [
            'username' => $username,
            'email' => $email,
            'password' => 'clave-notif-1',
        ]);
        $this->assertSame(201, $status, 'register');

        [$status, $body] = $this->dispatchJson('POST', '/api/auth/login', [
            'email' => $email,
            'password' => 'clave-notif-1',
        ]);
        $this->assertSame(200, $status, 'login');

        return [$body['data']['token'], (int) $body['data']['user']['id']];
    }

    private function momento(int $segundos): string
    {
        return date('Y-m-d H:i:s', strtotime('2026-09-01 08:00:00') + $segundos);
    }

    private function notificar(int $userId, string $createdAt, ?string $readAt = null): string
    {
        $id = 'notif_' . bin2hex(random_bytes(8));

        self::$pdo->prepare(
            'INSERT INTO notifications (id, user_id, type, title, message, data, read_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$id, $userId, 'test.event', 'Título de prueba', 'Mensaje de prueba', '{}', $readAt, $createdAt, $createdAt]);

        return $id;
    }

    private function ids(array $data): array
    {
        return array_map(fn(array $row) => (string) $row['id'], $data);
    }

    public function test_paginacion_dos_paginas_y_per_page_por_defecto(): void
    {
        [$token, $userId] = $this->usuario('notif_page', 'notif.page@test.local');

        $sembradas = [];
        for ($i = 0; $i < 25; $i++) {
            $sembradas[] = $this->notificar($userId, $this->momento($i));
        }

        [$status, $body] = $this->dispatchJson('GET', '/api/notifications?page=1&perPage=20', [], $token);
        $this->assertSame(200, $status);
        $this->assertTrue($body['success']);
        $this->assertCount(20, $body['data'], 'Página 1 devuelve perPage items');
        $this->assertSame(25, (int) $body['meta']['total']);
        $this->assertSame(20, (int) $body['meta']['per_page']);
        $this->assertSame(1, (int) $body['meta']['current_page']);
        $this->assertSame(2, (int) $body['meta']['last_page']);
        $this->assertSame(25, (int) $body['unread_count']);

        [$status, $body2] = $this->dispatchJson('GET', '/api/notifications?page=2&perPage=20', [], $token);
        $this->assertSame(200, $status);
        $this->assertCount(5, $body2['data'], 'Página 2 devuelve el resto');
        $this->assertSame(2, (int) $body2['meta']['current_page']);
        $this->assertSame(25, (int) $body2['meta']['total']);

        $idsPagina1 = $this->ids($body['data']);
        $idsPagina2 = $this->ids($body2['data']);
        $this->assertSame([], array_intersect($idsPagina1, $idsPagina2), 'Las páginas no se solapan');
        $this->assertEqualsCanonicalizing($sembradas, array_merge($idsPagina1, $idsPagina2), 'Entre ambas páginas están todas');

        [$status, $body3] = $this->dispatchJson('GET', '/api/notifications', [], $token);
        $this->assertSame(200, $status);
        $this->assertSame(20, (int) $body3['meta']['per_page'], 'perPage por defecto = 20');
        $this->assertCount(20, $body3['data']);

        [$status, $body4] = $this->dispatchJson('GET', '/api/notifications?page=0&perPage=5', [], $token);
        $this->assertSame(200, $status);
        $this->assertSame(1, (int) $body4['meta']['current_page'], 'page=0 se acota a 1');
        $this->assertCount(5, $body4['data']);
    }

    public function test_per_page_se_acota_al_rango_1_100(): void
    {
        [$token, $userId] = $this->usuario('notif_clamp', 'notif.clamp@test.local');

        for ($i = 0; $i < 5; $i++) {
            $this->notificar($userId, $this->momento($i));
        }

        [$status, $body] = $this->dispatchJson('GET', '/api/notifications?perPage=1000', [], $token);
        $this->assertSame(200, $status);
        $this->assertSame(100, (int) $body['meta']['per_page'], 'perPage=1000 → 100');
        $this->assertCount(5, $body['data']);

        [$status, $body] = $this->dispatchJson('GET', '/api/notifications?perPage=0', [], $token);
        $this->assertSame(200, $status);
        $this->assertSame(1, (int) $body['meta']['per_page'], 'perPage=0 → 1');
        $this->assertCount(1, $body['data']);

        [$status, $body] = $this->dispatchJson('GET', '/api/notifications?perPage=-10', [], $token);
        $this->assertSame(200, $status);
        $this->assertSame(1, (int) $body['meta']['per_page'], 'perPage=-10 → 1');
    }

    public function test_filtro_unread_sigue_funcionando(): void
    {
        [$token, $userId] = $this->usuario('notif_unread', 'notif.unread@test.local');

        for ($i = 0; $i < 8; $i++) {
            $this->notificar($userId, $this->momento($i));
        }
        for ($i = 8; $i < 12; $i++) {
            $this->notificar($userId, $this->momento($i), $this->momento($i + 100));
        }

        [$status, $body] = $this->dispatchJson('GET', '/api/notifications?unread=1', [], $token);
        $this->assertSame(200, $status);
        $this->assertCount(8, $body['data'], 'unread=1 solo devuelve no leídas');
        $this->assertSame(8, (int) $body['meta']['total'], 'El total refleja el filtro');
        $this->assertSame(8, (int) $body['unread_count']);
        foreach ($body['data'] as $row) {
            $this->assertNull($row['read_at'], 'Ninguna leída en el filtro unread');
        }

        [$status, $body2] = $this->dispatchJson('GET', '/api/notifications?unread=1&perPage=5&page=2', [], $token);
        $this->assertSame(200, $status);
        $this->assertCount(3, $body2['data'], 'Segunda página del filtro');
        $this->assertSame(2, (int) $body2['meta']['last_page']);
        $this->assertSame(8, (int) $body2['meta']['total']);

        [$status, $body3] = $this->dispatchJson('GET', '/api/notifications?unread=0', [], $token);
        $this->assertSame(200, $status);
        $this->assertCount(12, $body3['data'], 'unread=0 no filtra');
        $this->assertSame(12, (int) $body3['meta']['total']);
        $this->assertSame(8, (int) $body3['unread_count'], 'unread_count no depende del filtro');
    }

    public function test_unread_count_tras_marcar_una_y_todas(): void
    {
        [$token, $userId] = $this->usuario('notif_count', 'notif.count@test.local');

        $noLeidas = [];
        for ($i = 0; $i < 4; $i++) {
            $noLeidas[] = $this->notificar($userId, $this->momento($i));
        }
        for ($i = 4; $i < 6; $i++) {
            $this->notificar($userId, $this->momento($i), $this->momento($i + 100));
        }

        [$status, $body] = $this->dispatchJson('GET', '/api/notifications', [], $token);
        $this->assertSame(200, $status);
        $this->assertSame(4, (int) $body['unread_count']);
        $this->assertSame(6, (int) $body['meta']['total']);

        [$status] = $this->dispatchJson('POST', "/api/notifications/{$noLeidas[0]}/read", [], $token);
        $this->assertSame(200, $status);

        [$status, $body] = $this->dispatchJson('GET', '/api/notifications', [], $token);
        $this->assertSame(200, $status);
        $this->assertSame(3, (int) $body['unread_count'], 'Marcar una leída baja el contador');

        [$status, $body] = $this->dispatchJson('POST', '/api/notifications/read-all', [], $token);
        $this->assertSame(200, $status);
        $this->assertSame(3, (int) $body['marked'], 'read-all marca las 3 restantes');

        [$status, $body] = $this->dispatchJson('GET', '/api/notifications', [], $token);
        $this->assertSame(200, $status);
        $this->assertSame(0, (int) $body['unread_count'], 'Tras marcar todas, contador a 0');
        $this->assertSame(6, (int) $body['meta']['total']);

        [$status, $body] = $this->dispatchJson('GET', '/api/notifications?unread=1', [], $token);
        $this->assertSame(200, $status);
        $this->assertSame(0, (int) $body['meta']['total']);
        $this->assertSame([], $body['data']);
        $this->assertSame(0, (int) $body['unread_count']);
    }

    public function test_no_expone_notificaciones_de_otro_usuario(): void
    {
        [$tokenA, $idA] = $this->usuario('notif_owner_a', 'notif.owner.a@test.local');
        [$tokenB, $idB] = $this->usuario('notif_owner_b', 'notif.owner.b@test.local');

        $idsA = [
            $this->notificar($idA, $this->momento(0)),
            $this->notificar($idA, $this->momento(1)),
            $this->notificar($idA, $this->momento(2), $this->momento(200)),
        ];

        $idsB = [];
        for ($i = 0; $i < 4; $i++) {
            $idsB[] = $this->notificar($idB, $this->momento($i));
        }

        [$status, $body] = $this->dispatchJson('GET', '/api/notifications', [], $tokenA);
        $this->assertSame(200, $status);
        $this->assertSame(3, (int) $body['meta']['total'], 'A solo ve lo suyo');
        $this->assertSame(2, (int) $body['unread_count'], 'El contador es solo de A');
        $this->assertSame([], array_intersect($this->ids($body['data']), $idsB), 'Nunca aparecen ids de B');
        $this->assertEqualsCanonicalizing($idsA, $this->ids($body['data']));

        [$status, $body] = $this->dispatchJson('GET', '/api/notifications?unread=1', [], $tokenA);
        $this->assertSame(200, $status);
        $this->assertSame([], array_intersect($this->ids($body['data']), $idsB), 'unread=1 tampoco filtra a otro usuario');

        [$status, $body] = $this->dispatchJson('GET', "/api/notifications?user_id={$idB}&perPage=100", [], $tokenA);
        $this->assertSame(200, $status);
        $this->assertSame(3, (int) $body['meta']['total'], 'user_id ajeno no cambia el scope');
        $this->assertSame([], array_intersect($this->ids($body['data']), $idsB));

        [$status] = $this->dispatchJson('GET', "/api/notifications/{$idsB[0]}", [], $tokenA);
        $this->assertSame(403, $status, 'A no puede leer una notificación de B');

        [$status, $body] = $this->dispatchJson('GET', '/api/notifications?perPage=100', [], $tokenB);
        $this->assertSame(200, $status);
        $this->assertSame(4, (int) $body['meta']['total'], 'B ve exactamente las suyas');
        $this->assertSame(4, (int) $body['unread_count']);
    }

    public function test_sin_token_es_401(): void
    {
        [$status] = $this->dispatchJson('GET', '/api/notifications');
        $this->assertSame(401, $status);
    }
}
