<?php

namespace Tests\Unit\Tournaments;

use Apps\Tournaments\Services\Mapeos;
use PHPUnit\Framework\TestCase;

class MapeosTest extends TestCase
{
    public function test_formatos_round_trip(): void
    {
        foreach (['eliminacion-directa', 'doble-eliminacion', 'round-robin', 'grupos', 'liga'] as $api) {
            $db = Mapeos::formatoDesdeApi($api);
            $this->assertNotNull($db);
            $this->assertSame($api, Mapeos::formatoHaciaApi($db));
        }

        $this->assertNull(Mapeos::formatoDesdeApi('inexistente'));
        $this->assertNull(Mapeos::formatoDesdeApi(null));
        $this->assertNull(Mapeos::formatoHaciaApi(null));
    }

    public function test_visibilidad_round_trip(): void
    {
        $this->assertSame('public', Mapeos::visibilidadDesdeApi('publico'));
        $this->assertSame('private', Mapeos::visibilidadDesdeApi('privado'));
        $this->assertSame('publico', Mapeos::visibilidadHaciaApi('public'));
        $this->assertSame('privado', Mapeos::visibilidadHaciaApi('private'));
        $this->assertNull(Mapeos::visibilidadDesdeApi('raro'));
    }
}