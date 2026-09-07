<?php

namespace Tests\Unit\Tournaments;

use Apps\Tournaments\Services\ReglasTorneo;
use PHPUnit\Framework\TestCase;

class ReglasTorneoTest extends TestCase
{
    public function test_publico_se_publica_sin_equipos(): void
    {
        $r = ReglasTorneo::puedePublicar(['status' => 'draft', 'visibility' => 'public', 'aceptados' => 0]);
        $this->assertTrue($r['ok']);
    }

    public function test_privado_exige_dos_aceptados(): void
    {
        $r = ReglasTorneo::puedePublicar(['status' => 'draft', 'visibility' => 'private', 'aceptados' => 1]);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('al menos 2', $r['motivo']);

        $r = ReglasTorneo::puedePublicar(['status' => 'draft', 'visibility' => 'private', 'aceptados' => 2]);
        $this->assertTrue($r['ok']);
    }

    public function test_no_draft_no_publica(): void
    {
        $r = ReglasTorneo::puedePublicar(['status' => 'open', 'visibility' => 'public']);
        $this->assertFalse($r['ok']);
    }

    public function test_iniciar_requiere_draw(): void
    {
        $r = ReglasTorneo::puedeIniciar(['status' => 'open', 'tiene_draw' => false]);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('sorteo', $r['motivo']);

        $r = ReglasTorneo::puedeIniciar(['status' => 'open', 'tiene_draw' => true]);
        $this->assertTrue($r['ok']);

        $r = ReglasTorneo::puedeIniciar(['status' => 'draft', 'tiene_draw' => true]);
        $this->assertFalse($r['ok']);
    }

    public function test_finalizar_requiere_final_con_ganador(): void
    {
        $r = ReglasTorneo::puedeFinalizar(['status' => 'live', 'final_con_ganador' => false]);
        $this->assertFalse($r['ok']);

        $r = ReglasTorneo::puedeFinalizar(['status' => 'live', 'final_con_ganador' => true]);
        $this->assertTrue($r['ok']);

        $r = ReglasTorneo::puedeFinalizar(['status' => 'finished', 'final_con_ganador' => true]);
        $this->assertFalse($r['ok']);
    }

    public function test_campos_editables_por_estado(): void
    {
        $draft = ReglasTorneo::camposEditables('draft');
        $this->assertContains('sport', $draft);
        $this->assertContains('format', $draft);
        $this->assertContains('max_participants', $draft);

        $open = ReglasTorneo::camposEditables('open');
        $this->assertNotContains('sport', $open);
        $this->assertNotContains('format', $open);
        $this->assertNotContains('max_participants', $open);
        $this->assertContains('title', $open);

        $this->assertSame([], ReglasTorneo::camposEditables('live'));
        $this->assertSame([], ReglasTorneo::camposEditables('finished'));
    }

    public function test_filtrar_editables_descarta_bloqueados(): void
    {
        $filtrado = ReglasTorneo::filtrarEditables('open', [
            'title' => 'Nuevo', 'sport' => 'Fútbol', 'format' => 'grupos', 'max_participants' => 99,
        ]);
        $this->assertSame(['title' => 'Nuevo'], $filtrado);

        // Mapeo de ENUMs al filtrar
        $filtrado = ReglasTorneo::filtrarEditables('draft', ['visibility' => 'publico', 'format' => 'grupos']);
        $this->assertSame('public', $filtrado['visibility']);
        $this->assertSame('groups', $filtrado['format']);
    }

    public function test_validar_solicitud(): void
    {
        $base = ['status' => 'open', 'is_individual' => false, 'registration_deadline' => null];

        $r = ReglasTorneo::validarSolicitud(['status' => 'draft'], 1, null, false, false);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('no está abierto', $r['motivo']);

        // El organizador puede inscribir equipos directamente en borrador (wizard/AG-01)
        $r = ReglasTorneo::validarSolicitud(['status' => 'draft'], 1, null, false, false, true);
        $this->assertTrue($r['ok']);

        $r = ReglasTorneo::validarSolicitud($base + ['is_individual' => true], null, null, false, false);
        $this->assertFalse($r['ok']);

        $r = ReglasTorneo::validarSolicitud($base, null, 5, false, false);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('por equipos', $r['motivo']);

        $r = ReglasTorneo::validarSolicitud($base, 1, 5, false, false);
        $this->assertFalse($r['ok']);

        $r = ReglasTorneo::validarSolicitud($base, 1, null, true, false);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('ya tiene', $r['motivo']);

        $r = ReglasTorneo::validarSolicitud($base, 1, null, false, true);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('cupo', $r['motivo']);

        $r = ReglasTorneo::validarSolicitud(array_merge($base, ['registration_deadline' => date('Y-m-d H:i:s', time() - 3600)]), 1, null, false, false);
        $this->assertFalse($r['ok']);

        $r = ReglasTorneo::validarSolicitud($base, 1, null, false, false);
        $this->assertTrue($r['ok']);
    }

    public function test_puede_decidir(): void
    {
        $this->assertTrue(ReglasTorneo::puedeDecidir('pending', 'accepted')['ok']);
        $this->assertTrue(ReglasTorneo::puedeDecidir('pending', 'rejected')['ok']);
        $this->assertFalse(ReglasTorneo::puedeDecidir('accepted', 'accepted')['ok']);
        $this->assertFalse(ReglasTorneo::puedeDecidir('pending', 'cancel')['ok']);
    }
}