<?php

namespace Tests\Feature;

use Apollo\Core\Database\Connection\DatabaseManager;
use Apps\Tournaments\Repositories\AuditLogRepository;
use Apps\Tournaments\Repositories\TeamRepository;
use Apps\Tournaments\Repositories\TournamentRepository;
use Apps\Tournaments\Services\AnnouncementService;
use Apps\Tournaments\Services\AuditLogService;
use Apps\Tournaments\Services\RegistrationService;
use Apps\Tournaments\Services\TeamService;
use Apps\Tournaments\Services\TournamentService;
use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Tablón de anuncios (ANN-01): crear/eliminar solo organizador, lectura
 * pública, notificación in-app a participantes aceptados al publicar.
 * SQLite :memory: con migraciones reales.
 */
class AnnouncementServiceTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static AnnouncementService $announcements;
    private static TournamentService $tournaments;
    private static RegistrationService $registrations;
    private static TeamService $teams;

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

        $audit = new AuditLogService(new AuditLogRepository());
        self::$tournaments = new TournamentService(new TournamentRepository(), $audit);
        self::$registrations = new RegistrationService(self::$tournaments, $audit);
        self::$teams = new TeamService(new TeamRepository(), $audit);
        self::$announcements = new AnnouncementService($audit);
    }

    private function createUser(string $username, string $email): int
    {
        self::$pdo->prepare("INSERT INTO users (username, email, password, status, email_verified_at) VALUES (?, ?, 'x', 'active', ?)")
            ->execute([$username, $email, date('Y-m-d H:i:s')]);
        return (int) self::$pdo->lastInsertId();
    }

    public function test_crear_listar_eliminar_y_notificacion(): void
    {
        $organizer = $this->createUser('ann1', 'ann1@test.local');
        $participant = $this->createUser('ann2', 'ann2@test.local');
        $outsider = $this->createUser('ann3', 'ann3@test.local');

        // Torneo abierto + inscripción aceptada del participante.
        $tournament = self::$tournaments->create($organizer, [
            'title' => 'Copa Anuncios',
            'format' => 'round-robin',
            'max_participants' => 8,
            'visibility' => 'publico',
        ]);
        $tournamentId = (int) $tournament['id'];
        self::$tournaments->transition($organizer, $tournamentId, 'publish');

        $team = self::$teams->create($participant, ['name' => 'Avisados FC']);
        $r = self::$registrations->apply($participant, $tournamentId, ['team_id' => (int) $team['id']]);
        self::$registrations->decide($organizer, $tournamentId, (int) $r['id'], ['action' => 'accepted']);

        // Crear anuncio (organizador).
        $announcement = self::$announcements->create($organizer, $tournamentId, [
            'title' => 'Cambio de horario',
            'body' => 'La final se juega el sábado a las 18:00.',
            'pinned' => true,
        ]);
        $this->assertSame('Cambio de horario', $announcement['title']);
        $this->assertSame('1', (string) $announcement['pinned']);
        $announcementId = (int) $announcement['id'];

        // Lectura pública: lo ve cualquiera.
        $list = self::$announcements->listPublic($tournamentId);
        $this->assertCount(1, $list);
        $this->assertSame('Cambio de horario', $list[0]['title']);

        // Notificación in-app al participante aceptado (y solo a él).
        $stmt = self::$pdo->prepare('SELECT * FROM notifications WHERE user_id = ? AND type = ?');
        $stmt->execute([$participant, 'anuncio.nuevo']);
        $this->assertCount(1, $stmt->fetchAll(PDO::FETCH_ASSOC), 'Participante notificado');
        $stmt->execute([$outsider, 'anuncio.nuevo']);
        $this->assertCount(0, $stmt->fetchAll(PDO::FETCH_ASSOC), 'El tercero no se notifica');

        // Terceros no pueden crear ni eliminar (403).
        try {
            self::$announcements->create($outsider, $tournamentId, ['title' => 'X', 'body' => 'Y']);
            $this->fail('El tercero no crea anuncios');
        } catch (\RuntimeException $e) {
            $this->assertSame(403, $e->getCode());
        }
        try {
            self::$announcements->delete($outsider, $tournamentId, $announcementId);
            $this->fail('El tercero no elimina anuncios');
        } catch (\RuntimeException $e) {
            $this->assertSame(403, $e->getCode());
        }

        // Validación: título/contenido obligatorios.
        try {
            self::$announcements->create($organizer, $tournamentId, ['title' => '', 'body' => 'X']);
            $this->fail('Título obligatorio');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('título', $e->getMessage());
        }

        // Orden: pinned primero.
        self::$announcements->create($organizer, $tournamentId, ['title' => 'Normal', 'body' => 'Y']);
        $list = self::$announcements->listPublic($tournamentId);
        $this->assertSame('Cambio de horario', $list[0]['title'], 'Pinned primero');

        // Eliminar.
        $this->assertTrue(self::$announcements->delete($organizer, $tournamentId, $announcementId));
        $this->assertCount(1, self::$announcements->listPublic($tournamentId));
    }
}