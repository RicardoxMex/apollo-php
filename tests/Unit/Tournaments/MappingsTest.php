<?php

namespace Tests\Unit\Tournaments;

use Apps\Tournaments\Services\Mappings;
use PHPUnit\Framework\TestCase;

class MappingsTest extends TestCase
{
    public function test_formats_round_trip(): void
    {
        foreach (['eliminacion-directa', 'doble-eliminacion', 'round-robin', 'grupos', 'liga'] as $api) {
            $db = Mappings::formatFromApi($api);
            $this->assertNotNull($db);
            $this->assertSame($api, Mappings::formatToApi($db));
        }

        $this->assertNull(Mappings::formatFromApi('inexistente'));
        $this->assertNull(Mappings::formatFromApi(null));
        $this->assertNull(Mappings::formatToApi(null));
    }

    public function test_visibility_round_trip(): void
    {
        $this->assertSame('public', Mappings::visibilityFromApi('publico'));
        $this->assertSame('private', Mappings::visibilityFromApi('privado'));
        $this->assertSame('publico', Mappings::visibilityToApi('public'));
        $this->assertSame('privado', Mappings::visibilityToApi('private'));
        $this->assertNull(Mappings::visibilityFromApi('raro'));
    }
}