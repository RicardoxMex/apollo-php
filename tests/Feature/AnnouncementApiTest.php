<?php

namespace Tests\Feature;

use Apollo\Core\Database\Connection\DatabaseManager;
use Apollo\Core\Http\Request;
use Tests\TestCase;
use PDO;

/**
 * API de anuncios (ANN-02): GET público, POST/DELETE organizador, email a
 * participantes con notify_email (driver log). SQLite :memory:, kernel real.
 */
class AnnouncementApiTest extends TestCase
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

    private function usuarioConEmailVerificado(string $username, string $email): string
    {
        $this->dispatchJson('POST', '/api/auth/register', [
            'username' => $username, 'email' => $email, 'password' => 'clave-ann-1',
        ]);
        // Verificar email (D2) para poder publicar.
        $stmt = self::$pdo->prepare('SELECT token FROM email_verifications WHERE user_id = (SELECT id FROM users WHERE email = ?) ORDER BY id DESC LIMIT 1');
        $stmt->execute([$email]);
        $hash = $stmt->fetchColumn();
        $plain = 'e2e-ann-' . bin2hex(random_bytes(6));
        self::$pdo->prepare('UPDATE email_verifications SET token = ? WHERE token = ?')
            ->execute([hash('sha256', $plain), $hash]);
        $this->dispatchJson('POST', '/api/auth/verify-email', ['token' => $plain]);

        [$status, $body] = $this->dispatchJson('POST', '/api/auth/login', [
            'email' => $email, 'password' => 'clave-ann-1',
        ]);
        $this->assertSame(200, $status);
        return $body['data']['token'];
    }

    public function test_announcements_api_flow(): void
    {
        $orgToken = $this->usuarioConEmailVerificado('org_ann', 'org.ann@test.local');
        $participantToken = $this->usuarioConEmailVerificado('part_ann', 'part.ann@test.local');
        $thirdToken = $this->usuarioConEmailVerificado('ter_ann', 'ter.ann@test.local');

        // Torneo publicado + participante aceptado.
        [$status, $body] = $this->dispatchJson('POST', '/api/tournaments', [
            'title' => 'Copa Anuncios API', 'format' => 'round-robin',
            'max_participants' => 8, 'visibility' => 'publico',
        ], $orgToken);
        $this->assertSame(201, $status);
        $tournamentId = $body['data']['id'];
        [$status] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/publish", [], $orgToken);
        $this->assertSame(200, $status);

        [$status, $body] = $this->dispatchJson('POST', '/api/teams', ['name' => 'Notificados FC'], $participantToken);
        $this->assertSame(201, $status);
        [$status, $body] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/registrations", ['team_id' => $body['data']['id']], $participantToken);
        $this->assertSame(201, $status);
        [$status] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/registrations/{$body['data']['id']}/decide", ['action' => 'accepted'], $orgToken);
        $this->assertSame(200, $status);

        // Publicar con notify_email → el participante recibe notificación y email.
        [$status, $body] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/announcements", [
            'title' => 'Programa confirmado',
            'body' => 'Todos los partidos el sábado desde las 9:00.',
            'pinned' => true,
            'notify_email' => true,
        ], $orgToken);
        $this->assertSame(201, $status, 'Anuncio publicado');
        $this->assertSame('1', (string) $body['data']['pinned']);
        $announcementId = $body['data']['id'];

        // GET público (sin token).
        [$status, $body] = $this->dispatchJson('GET', "/api/tournaments/{$tournamentId}/announcements");
        $this->assertSame(200, $status);
        $this->assertCount(1, $body['data']);
        $this->assertSame('Programa confirmado', $body['data'][0]['title']);

        // Notificación in-app al participante.
        $stmt = self::$pdo->prepare("SELECT COUNT(*) FROM notifications WHERE type = 'anuncio.nuevo'");
        $stmt->execute();
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'Notificación in-app emitida');

        // Email al participante (driver log, best-effort).
        $mailDir = dirname(__DIR__, 2) . '/runtime/logs/mail';
        $found = false;
        foreach (glob($mailDir . '/*.html') ?: [] as $file) {
            $content = (string) file_get_contents($file);
            if (str_contains($content, 'part.ann@test.local') && str_contains($content, 'Programa confirmado')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Email de anuncio enviado al participante (log)');

        // 403 a terceros; 422 body inválido; 401 sin token; DELETE.
        [$status] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/announcements", [
            'title' => 'X', 'body' => 'Y',
        ], $thirdToken);
        $this->assertSame(403, $status);

        [$status] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/announcements", [
            'title' => '', 'body' => 'Y',
        ], $orgToken);
        $this->assertSame(422, $status);

        [$status] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/announcements", [
            'title' => 'X', 'body' => 'Y',
        ]);
        $this->assertSame(401, $status);

        [$status] = $this->dispatchJson('DELETE', "/api/tournaments/{$tournamentId}/announcements/{$announcementId}", [], $orgToken);
        $this->assertSame(200, $status, 'Anuncio eliminado');
        [$status, $body] = $this->dispatchJson('GET', "/api/tournaments/{$tournamentId}/announcements");
        $this->assertCount(0, $body['data']);
    }
}