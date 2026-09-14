<?php

namespace Tests\Feature;

use Apollo\Core\Database\Connection\DatabaseManager;
use Apps\Tournaments\Repositories\AuditLogRepository;
use Apps\Tournaments\Repositories\TeamRepository;
use Apps\Tournaments\Repositories\TournamentRepository;
use Apps\Tournaments\Services\AuditLogService;
use Apps\Tournaments\Services\PaymentService;
use Apps\Tournaments\Services\RegistrationService;
use Apps\Tournaments\Services\TeamService;
use Apps\Tournaments\Services\TournamentService;
use PHPUnit\Framework\TestCase;
use PDO;

/**
 * Pagos manuales (PAY-01): PaymentService register/list/delete solo para el
 * organizador, monto > 0, método permitido, inscripción del torneo, auditoría;
 * y fee real > 0 en torneos. SQLite :memory: con migraciones reales.
 */
class PaymentServiceTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static PaymentService $payments;
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
        self::$payments = new PaymentService($audit);
    }

    private function createUser(string $username, string $email): int
    {
        self::$pdo->prepare("INSERT INTO users (username, email, password, status) VALUES (?, ?, 'x', 'active')")
            ->execute([$username, $email]);
        return (int) self::$pdo->lastInsertId();
    }

    public function test_fee_persistido_y_pagos_manuales(): void
    {
        $organizer = $this->createUser('pay1', 'pay1@test.local');
        $applicant = $this->createUser('pay2', 'pay2@test.local');

        // Fee real > 0 en la creación (SQLite no escala decimals como MySQL).
        $tournament = self::$tournaments->create($organizer, [
            'title' => 'Copa Pagos',
            'format' => 'round-robin',
            'max_participants' => 8,
            'visibility' => 'publico',
            'registration_fee' => 250,
            'currency' => 'MXN',
        ]);
        $this->assertSame(250.0, (float) $tournament['registration_fee']);
        $this->assertSame('MXN', $tournament['currency']);
        $tournamentId = (int) $tournament['id'];

        // Inscripción (el organizador en borrador) para ligar el pago.
        $team = self::$teams->create($organizer, ['name' => 'Pagos FC']);
        $r = self::$registrations->apply($organizer, $tournamentId, ['team_id' => (int) $team['id']]);

        // Registrar pago manual.
        $payment = self::$payments->register($organizer, $tournamentId, [
            'registration_id' => (int) $r['id'],
            'method' => 'transferencia',
            'reference' => 'REF-001',
            'notes' => 'Depósito del equipo',
        ]);
        $this->assertSame(250.0, (float) $payment['amount'], 'Monto sugerido = fee');
        $this->assertSame('transferencia', $payment['method']);
        $this->assertSame('paid', $payment['status']);

        // Listado con inscripción resuelta.
        $list = self::$payments->list($organizer, $tournamentId);
        $this->assertCount(1, $list);
        $this->assertSame('Pagos FC', $list[0]['registration_name']);

        // El solicitante NO puede listar/registrar/eliminar (403).
        try {
            self::$payments->list($applicant, $tournamentId);
            $this->fail('El solicitante no lista pagos');
        } catch (\RuntimeException $e) {
            $this->assertSame(403, $e->getCode());
        }
        try {
            self::$payments->register($applicant, $tournamentId, ['method' => 'efectivo']);
            $this->fail('El solicitante no registra pagos');
        } catch (\RuntimeException $e) {
            $this->assertSame(403, $e->getCode());
        }

        // Validaciones: monto <= 0 y método inválido → 400 (InvalidArgumentException).
        try {
            self::$payments->register($organizer, $tournamentId, ['amount' => 0, 'method' => 'efectivo']);
            $this->fail('Monto 0 rechazado');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('monto', $e->getMessage());
        }
        try {
            self::$payments->register($organizer, $tournamentId, ['method' => 'cripto']);
            $this->fail('Método inválido rechazado');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Método', $e->getMessage());
        }

        // Eliminar (registro erróneo) y auditoría.
        $paymentId = (int) $payment['id'];
        $this->assertTrue(self::$payments->delete($organizer, $tournamentId, $paymentId));
        $this->assertCount(0, self::$payments->list($organizer, $tournamentId));

        $stmt = self::$pdo->prepare("SELECT action FROM audit_logs WHERE entity_type = 'payment' ORDER BY id DESC LIMIT 2");
        $stmt->execute();
        $actions = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'action');
        sort($actions);
        $this->assertSame(['pago:eliminar', 'pago:registrar'], $actions);
    }
}