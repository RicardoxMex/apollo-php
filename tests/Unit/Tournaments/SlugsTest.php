<?php

namespace Tests\Unit\Tournaments;

use Apps\Tournaments\Services\Slugs;
use PHPUnit\Framework\TestCase;

class SlugsTest extends TestCase
{
    public function test_slug_ascii_sin_diacriticos(): void
    {
        $this->assertSame('copa-primavera-2026', Slugs::from('Copa Primavera 2026'));
        $this->assertSame('futbol-5x5-final', Slugs::from('Fútbol 5x5 — Final'));
        $this->assertSame('exito-si', Slugs::from('¡Éxito! ¿Sí?'));
        $this->assertSame('aeiouun', Slugs::from('áéíóúüñ'));
    }

    public function test_slug_normaliza_espacios_y_corta_a_60(): void
    {
        $this->assertSame('torneo-de-ajedrez', Slugs::from('  Torneo   de  Ajedrez  '));
        $this->assertSame('torneo', Slugs::from('???'));
        $this->assertLessThanOrEqual(60, strlen(Slugs::from(str_repeat('a b ', 40))));
    }
}