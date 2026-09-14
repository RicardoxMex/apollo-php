<?php

namespace Tests\Feature;

use Apollo\Core\Database\Connection\DatabaseManager;
use Apps\Tournaments\Repositories\AuditLogRepository;
use Apps\Tournaments\Repositories\TeamRepository;
use Apps\Tournaments\Repositories\TournamentRepository;
use Apps\Tournaments\Services\AuditLogService;
use Apps\Tournaments\Services\RegistrationService;
use Apps\Tournaments\Services\TeamService;
use Apps\Tournaments\Services\TournamentService;
use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Las inscripciones emiten notificaciones (tabla notifications) al organizador
 * cuando llega una solicitud y al solicitante cuando se decide.
 * SQLite :memory: con migraciones reales.
 */
class NotificationsTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static TournamentService $tournaments;
    private static TeamService $teams;
    private static RegistrationService $registrations;

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite no disponible');
        }

        new \Apollo\Core\Application(dirname(__DIR__, 2));
        \app('config');

        // Registra los core providers como en public/index.php (mailer, realtime…).
        foreach (\app('config')->get('providers.core', []) as $providerClass) {
            if (class_exists($providerClass)) {
                \app()->registerServiceProvider(new $providerClass(\app()));
            }
        }
        \app()->bootServiceProviders();

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

        $audit = new AuditLogService(new AuditLogRepository());
        self::$tournaments = new TournamentService(new TournamentRepository(), $audit);
        self::$teams = new TeamService(new TeamRepository(), $audit);
        self::$registrations = new RegistrationService(self::$tournaments, $audit);
    }

    private function createUser(string $username, string $email): int
    {
        $stmt = self::$pdo->prepare("INSERT INTO users (username, email, password, status, email_verified_at) VALUES (?, ?, ?, 'active', ?)");
        $stmt->execute([$username, $email, password_hash('secret', PASSWORD_DEFAULT), date('Y-m-d H:i:s')]);
        return (int) self::$pdo->lastInsertId();
    }

    private function notificaciones(int $userId): array
    {
        $stmt = self::$pdo->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at ASC');
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function test_inscripcion_notifica_organizador_y_solicitante(): void
    {
        $organizer = $this->createUser('org', 'org@notif.test');
        $applicant = $this->createUser('jugador', 'jugador@notif.test');

        $team = self::$teams->create($organizer, ['name' => 'Alpha FC']);
        $team2 = self::$teams->create($organizer, ['name' => 'Beta FC']);

        $tournament = self::$tournaments->create($organizer, [
            'title' => 'Copa Notificaciones',
            'format' => 'eliminacion-directa',
            'max_participants' => 8,
            'visibility' => 'publico',
        ]);
        $tournamentId = (int) $tournament['id'];

        self::$tournaments->transition($organizer, $tournamentId, 'publish');

        // El solicitante aplica con su equipo → el organizador recibe la notificación
        $r = self::$registrations->apply($applicant, $tournamentId, ['team_id' => (int) $team2['id']]);
        $this->assertSame('pending', $r['status']);

        $orgNotes = $this->notificaciones($organizer);
        $this->assertCount(1, $orgNotes, 'El organizador recibe una notificación de nueva solicitud');
        $this->assertSame('registro.solicitado', $orgNotes[0]['type']);
        $this->assertStringContainsString('Beta FC', $orgNotes[0]['message']);

        // El organizador acepta → el solicitante recibe la notificación
        self::$registrations->decide($organizer, $tournamentId, (int) $r['id'], ['action' => 'accepted']);

        $appNotes = $this->notificaciones($applicant);
        $this->assertCount(1, $appNotes, 'El solicitante recibe una notificación de decisión');
        $this->assertSame('registro.decidido', $appNotes[0]['type']);
        $this->assertStringContainsString('aceptada', $appNotes[0]['message']);

        // El organizador NO se auto-notifica su propia inscripción directa
        self::$registrations->apply($organizer, $tournamentId, ['team_id' => (int) $team['id']]);
        $this->assertCount(1, $this->notificaciones($organizer), 'El organizador no recibe notificación de su propia solicitud');
    }

    public function test_inscripcion_envia_emails_transaccionales(): void
    {
        $organizer = $this->createUser('orgmail', 'orgmail@notif.test');
        $applicant = $this->createUser('jugmail', 'jugmail@notif.test');

        $team = self::$teams->create($organizer, ['name' => 'Alpha FC']);
        $team2 = self::$teams->create($organizer, ['name' => 'Beta FC']);

        $tournament = self::$tournaments->create($organizer, [
            'title' => 'Copa Emails',
            'format' => 'eliminacion-directa',
            'max_participants' => 8,
            'visibility' => 'publico',
        ]);
        $tournamentId = (int) $tournament['id'];
        self::$tournaments->transition($organizer, $tournamentId, 'publish');

        $mailDir = dirname(__DIR__, 2) . '/runtime/logs/mail';

        // Solicitud → email al organizador (con el nombre del equipo).
        $r = self::$registrations->apply($applicant, $tournamentId, ['team_id' => (int) $team2['id']]);
        $emailOrg = $this->ultimoEmailCon($mailDir, 'orgmail@notif.test');
        $this->assertNotNull($emailOrg, 'Email al organizador por solicitud');
        $body = (string) file_get_contents($emailOrg);
        $this->assertStringContainsString('Nueva solicitud de inscripción', $body);
        $this->assertStringContainsString('Beta FC', $body);
        $this->assertStringContainsString('Copa Emails', $body);

        // Decisión → email al solicitante.
        self::$registrations->decide($organizer, $tournamentId, (int) $r['id'], ['action' => 'accepted']);
        $emailApp = $this->ultimoEmailCon($mailDir, 'jugmail@notif.test');
        $this->assertNotNull($emailApp, 'Email al solicitante por decisión');
        $body = (string) file_get_contents($emailApp);
        $this->assertStringContainsString('aceptada', $body);
        $this->assertStringContainsString('Copa Emails', $body);
    }

    /**
     * Último email (por mtime) del directorio de logs que contenga el texto.
     * Los tests comparten runtime/logs/mail: la búsqueda por contenido evita
     * depender del orden/agregación de archivos de otras suites.
     */
    private function ultimoEmailCon(string $mailDir, string $texto): ?string
    {
        $files = glob($mailDir . '/*.html') ?: [];
        usort($files, fn($a, $b) => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));
        foreach ($files as $file) {
            if (str_contains((string) file_get_contents($file), $texto)) {
                return $file;
            }
        }
        return null;
    }
}