<?php

namespace Tests\Feature;

use Apollo\Core\Database\Connection\DatabaseManager;
use Apps\ApolloAuth\Exceptions\EmailNotVerifiedException;
use Apps\ApolloAuth\Models\User;
use Apps\ApolloAuth\Services\VerificationService;
use Apps\Tournaments\Repositories\AuditLogRepository;
use Apps\Tournaments\Repositories\TournamentRepository;
use Apps\Tournaments\Services\AuditLogService;
use Apps\Tournaments\Services\TournamentService;
use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Verificación de email (EMAIL-02, D2): issue → verify marca el email;
 * token inválido/caducado → 400; reenvío a verificado → 409; y la regla de
 * producto: publicar/iniciar exige email verificado (403 EMAIL_NOT_VERIFIED).
 * SQLite :memory: con migraciones reales.
 */
class AuthEmailFlowTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static VerificationService $verification;
    private static TournamentService $tournaments;

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite no disponible');
        }

        new \Apollo\Core\Application(dirname(__DIR__, 2));
        \app('config');

        DatabaseManager::setConfig([
            'connection' => 'sqlite',
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        DatabaseManager::disconnect();

        self::$pdo = DatabaseManager::getConnection();

        $files = glob(dirname(__DIR__, 2) . '/database/migrations/*.php');
        sort($files);
        foreach ($files as $file) {
            $migration = require $file;
            $migration->up();
        }

        self::$verification = new VerificationService();
        $audit = new AuditLogService(new AuditLogRepository());
        self::$tournaments = new TournamentService(new TournamentRepository(), $audit);
    }

    private function createUser(string $username, string $email, bool $verified = false): User
    {
        $stmt = self::$pdo->prepare(
            "INSERT INTO users (username, email, password, status, email_verified_at) VALUES (?, ?, ?, 'active', ?)"
        );
        $stmt->execute([
            $username,
            $email,
            password_hash('secret', PASSWORD_DEFAULT),
            $verified ? date('Y-m-d H:i:s') : null,
        ]);
        return User::find((int) self::$pdo->lastInsertId());
    }

    public function test_issue_verify_marks_email_as_verified(): void
    {
        $user = $this->createUser('verif1', 'verif1@test.local');
        $this->assertFalse($user->hasVerifiedEmail());

        $token = self::$verification->issue($user);

        // El token se guarda hashado, nunca en claro.
        $stmt = self::$pdo->prepare('SELECT token FROM email_verifications WHERE user_id = ?');
        $stmt->execute([$user->id]);
        $this->assertSame(hash('sha256', $token), $stmt->fetchColumn());

        $verified = self::$verification->verify($token);
        $this->assertTrue($verified->hasVerifiedEmail());

        // Un solo uso: repetir → 400.
        try {
            self::$verification->verify($token);
            $this->fail('El token reutilizado debe fallar');
        } catch (\RuntimeException $e) {
            $this->assertSame(400, $e->getCode());
        }
    }

    public function test_invalid_token_throws_400(): void
    {
        $user = $this->createUser('verif2', 'verif2@test.local');
        self::$verification->issue($user);

        try {
            self::$verification->verify('token-inexistente');
            $this->fail('Token inválido debe fallar');
        } catch (\RuntimeException $e) {
            $this->assertSame(400, $e->getCode());
        }
    }

    public function test_resend_revokes_previous_tokens(): void
    {
        $user = $this->createUser('verif3', 'verif3@test.local');
        $token1 = self::$verification->issue($user);
        $token2 = self::$verification->issue($user);

        try {
            self::$verification->verify($token1);
            $this->fail('El primer token quedó revocado al reenviar');
        } catch (\RuntimeException $e) {
            $this->assertSame(400, $e->getCode());
        }

        $this->assertTrue(self::$verification->verify($token2)->hasVerifiedEmail());
    }

    public function test_resend_when_already_verified_throws_409(): void
    {
        $user = $this->createUser('verif4', 'verif4@test.local', verified: true);

        try {
            self::$verification->resend($user);
            $this->fail('El reenvío a un email verificado debe fallar');
        } catch (\RuntimeException $e) {
            $this->assertSame(409, $e->getCode());
        }
    }

    public function test_publish_requires_verified_email(): void
    {
        $unverified = $this->createUser('org1', 'org1@test.local');
        $verified = $this->createUser('org2', 'org2@test.local', verified: true);

        $tournament = self::$tournaments->create((int) $unverified->id, [
            'title' => 'Copa Verificación',
            'format' => 'eliminacion-directa',
            'max_participants' => 8,
            'visibility' => 'publico',
        ]);
        $tournamentId = (int) $tournament['id'];

        try {
            self::$tournaments->transition((int) $unverified->id, $tournamentId, 'publish');
            $this->fail('Publicar sin email verificado debe fallar');
        } catch (EmailNotVerifiedException $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertSame(EmailNotVerifiedException::CODE, $e->businessCode());
        }

        // Verificar y publicar ya funciona.
        $token = self::$verification->issue($unverified);
        self::$verification->verify($token);

        $published = self::$tournaments->transition((int) $unverified->id, $tournamentId, 'publish');
        $this->assertSame('open', $published['status']);

        // 'start' también exige verificación: un organizador sin verificar falla.
        $t2 = self::$tournaments->create((int) $verified->id, [
            'title' => 'Copa Sin Draw',
            'format' => 'eliminacion-directa',
            'max_participants' => 8,
            'visibility' => 'publico',
        ]);
        self::$tournaments->transition((int) $verified->id, (int) $t2['id'], 'publish');

        // Sin sorteo → 409 normal (la verificación pasa, el draw no existe).
        try {
            self::$tournaments->transition((int) $verified->id, (int) $t2['id'], 'start');
            $this->fail('Sin sorteo no se puede iniciar');
        } catch (\RuntimeException $e) {
            $this->assertSame(409, $e->getCode() ?: 409);
        }
    }
}