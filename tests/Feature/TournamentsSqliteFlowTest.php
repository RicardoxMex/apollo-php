<?php

namespace Tests\Feature;

use Apollo\Core\Database\Connection\DatabaseManager;
use Apps\Tournaments\Repositories\AuditLogRepository;
use Apps\Tournaments\Repositories\MatchRepository;
use Apps\Tournaments\Repositories\TeamRepository;
use Apps\Tournaments\Repositories\TournamentRepository;
use Apps\Tournaments\Services\AuditLogService;
use Apps\Tournaments\Services\DrawService;
use Apps\Tournaments\Services\MatchService;
use Apps\Tournaments\Services\RegistrationService;
use Apps\Tournaments\Services\TeamService;
use Apps\Tournaments\Services\TournamentService;
use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Flujo completo del dominio de torneos sobre SQLite :memory: (migraciones reales):
 * equipo → torneo (draft) → inscripción directa del organizador → publicar → solicitud
 * pública → moderación → sorteo (byes + avances) → live → partidos con marcador/ganador
 * → finish. Valida los services sin necesidad de MySQL.
 * Requiere extension=pdo_sqlite (se omite si no está cargada).
 */
class TournamentsSqliteFlowTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static TournamentService $torneos;
    private static TeamService $equipos;
    private static RegistrationService $inscripciones;
    private static DrawService $sorteos;
    private static MatchService $partidos;

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
        self::$torneos = new TournamentService(new TournamentRepository(), $audit);
        self::$equipos = new TeamService(new TeamRepository(), $audit);
        self::$inscripciones = new RegistrationService(self::$torneos, $audit);
        self::$sorteos = new DrawService($audit);
        self::$partidos = new MatchService(new MatchRepository(), $audit);
    }

    private function crearUsuario(string $username, string $email): int
    {
        $stmt = self::$pdo->prepare("INSERT INTO users (username, email, password, status) VALUES (?, ?, ?, 'active')");
        $stmt->execute([$username, $email, password_hash('secret', PASSWORD_DEFAULT)]);
        return (int) self::$pdo->lastInsertId();
    }

    public function test_flujo_completo_torneo(): void
    {
        $organizador = $this->crearUsuario('organizador', 'org@test.local');
        $solicitante1 = $this->crearUsuario('jugador1', 'j1@test.local');

        // 1. Equipos (el creador queda como capitán)
        $equipo1 = self::$equipos->crear($organizador, ['name' => 'Los Pumas', 'contact' => 'a@x.com']);
        $equipo2 = self::$equipos->crear($organizador, ['name' => 'Las Águilas']);
        $equipo3 = self::$equipos->crear($organizador, ['name' => 'Los Tigres']);
        $this->assertNotNull($equipo1);
        $this->assertCount(1, $equipo1['captains']);
        $this->assertSame($organizador, (int) $equipo1['captains'][0]['id']);

        // 2. Torneo draft (privado, con stat configurada)
        $torneo = self::$torneos->crear($organizador, [
            'title' => 'Copa Verano 2026',
            'sport' => 'Fútbol',
            'format' => 'eliminacion-directa',
            'max_participants' => 8,
            'visibility' => 'privado',
            'stats' => [['label' => 'Goles', 'type' => 'number']],
        ]);
        $this->assertSame('draft', $torneo['status']);
        $this->assertSame('privado', $torneo['visibility']);
        $this->assertSame('eliminacion-directa', $torneo['format']);
        $this->assertCount(1, $torneo['stats']);
        $torneoId = (int) $torneo['id'];
        $statId = (int) $torneo['stats'][0]['id'];

        // 3. El público no puede inscribirse en borrador; el organizador sí (directo)
        try {
            self::$inscripciones->aplicar($solicitante1, $torneoId, ['team_id' => (int) $equipo3['id']]);
            $this->fail('El público no aplica en borrador');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('no está abierto', $e->getMessage());
        }

        $r1 = self::$inscripciones->aplicar($organizador, $torneoId, ['team_id' => (int) $equipo1['id']]);
        $r2 = self::$inscripciones->aplicar($organizador, $torneoId, ['team_id' => (int) $equipo2['id']]);
        $this->assertSame('pending', $r1['status']);

        // 4. Doble inscripción rechazada
        try {
            self::$inscripciones->aplicar($organizador, $torneoId, ['team_id' => (int) $equipo1['id']]);
            $this->fail('Debería rechazar la doble inscripción');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ya tiene', $e->getMessage());
        }

        // 5. Moderación: aceptar ambos. Publicar sin 2 aceptados → rechazado (ya no aplica).
        self::$inscripciones->decidir($organizador, $torneoId, (int) $r1['id'], ['action' => 'accepted']);
        self::$inscripciones->decidir($organizador, $torneoId, (int) $r2['id'], ['action' => 'accepted']);

        $participantes = self::$torneos->participantes($torneoId);
        $this->assertCount(2, $participantes);
        $this->assertSame('Los Pumas', $participantes[0]['display_name']);
        $this->assertSame(1, (int) $participantes[0]['seed']);

        try {
            self::$inscripciones->listar($torneoId, $solicitante1);
            $this->fail('Solo el organizador lista solicitudes');
        } catch (\RuntimeException $e) {
            $this->assertSame(403, $e->getCode());
        }

        // 6. Publicar → open. Un participante externo aplica con el 3er equipo → aceptado.
        $publicado = self::$torneos->transicion($organizador, $torneoId, 'publish');
        $this->assertSame('open', $publicado['status']);

        $r3 = self::$inscripciones->aplicar($solicitante1, $torneoId, ['team_id' => (int) $equipo3['id']]);
        $this->assertSame('pending', $r3['status']);
        self::$inscripciones->decidir($organizador, $torneoId, (int) $r3['id'], ['action' => 'accepted']);
        $this->assertCount(3, self::$torneos->participantes($torneoId));

        // 7. Iniciar sin sorteo → rechazado; generar bracket (3 equipos → bye) + iniciar
        try {
            self::$torneos->transicion($organizador, $torneoId, 'start');
            $this->fail('Debería exigir sorteo');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('sorteo', $e->getMessage());
        }

        $sorteo = self::$sorteos->generar($organizador, $torneoId, ['type' => 'bracket']);
        $this->assertSame(1, $sorteo['draw']['version']);
        $this->assertCount(2, $sorteo['rounds']);
        $this->assertCount(2, $sorteo['rounds'][0]['matches']); // bye + real
        $this->assertCount(1, $sorteo['rounds'][1]['matches']); // final
        $this->assertNull($sorteo['rounds'][0]['matches'][0]['participant_b_id']); // bye

        // Partidos oficiales 1:1 (ronda1: bye de Los Pumas + Águilas vs Tigres; ronda2: final)
        $partidos = self::$partidos->listar($torneoId);
        $this->assertCount(3, $partidos);
        $this->assertSame('Las Águilas', $partidos[1]['display_a']);
        $this->assertSame('Los Tigres', $partidos[1]['display_b']);

        $enVivo = self::$torneos->transicion($organizador, $torneoId, 'start');
        $this->assertSame('live', $enVivo['status']);

        // 8. Registro de resultados con avance automático al siguiente partido
        // 8a. El bye (Los Pumas, mejor seed) avanza solo a la final
        $matchBye = $partidos[0];
        $this->assertNull($matchBye['participant_b_id']);
        $pById = self::$torneos->participantes($torneoId);
        $participantePumas = (int) $pById[0]['id'];
        $partido = self::$partidos->actualizar($organizador, $torneoId, (int) $matchBye['id'], ['status' => 'completed']);
        $this->assertSame($participantePumas, (int) $partido['winner_participant_id']);

        // 8b. El real: marcador 3-1 → gana Las Águilas (a) y avanza a la final
        $matchReal = $partidos[1];
        $participanteAguilas = (int) $matchReal['participant_a_id'];
        $partido = self::$partidos->actualizar($organizador, $torneoId, (int) $matchReal['id'], [
            'status' => 'completed',
            'scores' => [['stat_id' => $statId, 'a' => 3, 'b' => 1]],
        ]);
        $this->assertSame($participanteAguilas, (int) $partido['winner_participant_id']);

        // La final quedó poblada con los dos ganadores (propagación round-trip)
        $partidos = self::$partidos->listar($torneoId);
        $final = $partidos[2];
        $this->assertSame($participantePumas, (int) $final['participant_a_id']);
        $this->assertSame($participanteAguilas, (int) $final['participant_b_id']);

        // 8c. Final: marcador 0-2 → campeón Las Águilas; draw_match propagado
        $partidoFinal = self::$partidos->actualizar($organizador, $torneoId, (int) $final['id'], [
            'status' => 'completed',
            'scores' => [['stat_id' => $statId, 'a' => 0, 'b' => 2]],
        ]);
        $this->assertSame($participanteAguilas, (int) $partidoFinal['winner_participant_id']);

        $drawFinal = self::$pdo->query('SELECT status, winner_participant_id FROM draw_matches ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('completed', $drawFinal['status']);
        $this->assertSame($participanteAguilas, (int) $drawFinal['winner_participant_id']);

        // 9. Finalizar → finished
        $finalizado = self::$torneos->transicion($organizador, $torneoId, 'finish');
        $this->assertSame('finished', $finalizado['status']);

        // 10. Auditoría completa
        $stmt = self::$pdo->query('SELECT COUNT(*) FROM audit_logs');
        $this->assertGreaterThan(10, (int) $stmt->fetchColumn());
    }
}