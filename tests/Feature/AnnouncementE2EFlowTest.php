<?php

namespace Tests\Feature;

use Apollo\Core\Database\Connection\DatabaseManager;
use Apollo\Core\Http\Request;
use Tests\TestCase;
use PDO;

/**
 * E2E del tablón de anuncios (M6, AC-08) por el kernel real: torneo →
 * participante aceptado → publicar anuncio con notify_email → GET público lo
 * muestra → notificación in-app del participante → email al participante
 * (driver log) → DELETE → 403 a terceros → 422.
 * SQLite :memory: con migraciones reales.
 */
class AnnouncementE2EFlowTest extends TestCase
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

    private function usuarioVerificado(string $username, string $email): string
    {
        $this->dispatchJson('POST', '/api/auth/register', [
            'username' => $username, 'email' => $email, 'password' => 'clave-ann-1',
        ]);
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

    public function test_e2e_tablon_anuncios(): void
    {
        $orgToken = $this->usuarioVerificado('org_e2e_ann', 'org.e2e.ann@test.local');
        $participantToken = $this->usuarioVerificado('part_e2e_ann', 'part.e2e.ann@test.local');
        $thirdToken = $this->usuarioVerificado('ter_e2e_ann', 'ter.e2e.ann@test.local');

        // Torneo publicado + participante aceptado.
        [$status, $body] = $this->dispatchJson('POST', '/api/tournaments', [
            'title' => 'Copa Avisos', 'format' => 'round-robin',
            'max_participants' => 8, 'visibility' => 'publico',
        ], $orgToken);
        $this->assertSame(201, $status);
        $tournamentId = $body['data']['id'];
        [$status] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/publish", [], $orgToken);
        $this->assertSame(200, $status);

        [$status, $body] = $this->dispatchJson('POST', '/api/teams', ['name' => 'Avisados FC'], $participantToken);
        $this->assertSame(201, $status);
        [$status, $body] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/registrations", ['team_id' => $body['data']['id']], $participantToken);
        $this->assertSame(201, $status);
        [$status] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/registrations/{$body['data']['id']}/decide", ['action' => 'accepted'], $orgToken);
        $this->assertSame(200, $status);

        // ── AC-08: publicar anuncio (con aviso por email) ──
        [$status, $body] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/announcements", [
            'title' => 'Cambio de sede',
            'body' => 'La sede cambia a la Cancha Norte.',
            'pinned' => true,
            'notify_email' => true,
        ], $orgToken);
        $this->assertSame(201, $status, 'Anuncio publicado (AC-08)');
        $this->assertSame('1', (string) $body['data']['pinned']);
        $announcementId = $body['data']['id'];

        // ── Visible en la lectura pública (sin token) ──
        [$status, $body] = $this->dispatchJson('GET', "/api/tournaments/{$tournamentId}/announcements");
        $this->assertSame(200, $status, 'Lectura pública (AC-08)');
        $this->assertCount(1, $body['data']);
        $this->assertSame('Cambio de sede', $body['data'][0]['title']);

        // ── Notificación in-app al participante aceptado ──
        $stmt = self::$pdo->prepare("SELECT * FROM notifications WHERE type = 'anuncio.nuevo' AND user_id = (SELECT id FROM users WHERE email = ?)");
        $stmt->execute(['part.e2e.ann@test.local']);
        $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $notes, 'Participante notificado in-app (AC-08)');
        $this->assertStringContainsString('Cambio de sede', $notes[0]['message']);

        // El tercero (no participante) NO se notifica.
        $stmt->execute(['ter.e2e.ann@test.local']);
        $this->assertCount(0, $stmt->fetchAll(PDO::FETCH_ASSOC), 'El tercero no se notifica');

        // ── Email al participante (driver log, best-effort) ──
        $mailDir = dirname(__DIR__, 2) . '/runtime/logs/mail';
        $found = false;
        foreach (glob($mailDir . '/*.html') ?: [] as $file) {
            $content = (string) file_get_contents($file);
            if (str_contains($content, 'part.e2e.ann@test.local') && str_contains($content, 'Cambio de sede')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Email de anuncio enviado al participante (AC-08/REQ-29)');

        // ── Seguridad y validación ──
        [$status] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/announcements", [
            'title' => 'X', 'body' => 'Y',
        ], $thirdToken);
        $this->assertSame(403, $status, 'Tercero no publica');

        [$status] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/announcements", [
            'title' => '', 'body' => 'Y',
        ], $orgToken);
        $this->assertSame(422, $status, 'Título obligatorio');

        [$status] = $this->dispatchJson('POST', "/api/tournaments/{$tournamentId}/announcements", [
            'title' => 'X', 'body' => 'Y',
        ]);
        $this->assertSame(401, $status, 'Sin token');

        // ── Eliminar ──
        [$status] = $this->dispatchJson('DELETE', "/api/tournaments/{$tournamentId}/announcements/{$announcementId}", [], $orgToken);
        $this->assertSame(200, $status, 'Anuncio eliminado');
        [$status, $body] = $this->dispatchJson('GET', "/api/tournaments/{$tournamentId}/announcements");
        $this->assertCount(0, $body['data']);
    }
}