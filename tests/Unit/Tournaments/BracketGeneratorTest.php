<?php

namespace Tests\Unit\Tournaments;

use Apps\Tournaments\Services\BracketGenerator;
use PHPUnit\Framework\TestCase;

class BracketGeneratorTest extends TestCase
{
    public function test_2_participantes_una_ronda(): void
    {
        $bracket = BracketGenerator::generarBracket([11, 22]);

        $this->assertCount(1, $bracket);
        $this->assertSame(1, $bracket[0]['round_number']);
        $this->assertSame(11, $bracket[0]['participant_a_id']);
        $this->assertSame(22, $bracket[0]['participant_b_id']);
        $this->assertNull($bracket[0]['next_match_index']);
        $this->assertNull($bracket[0]['next_slot']);
    }

    public function test_4_participantes_sin_byes_y_avances(): void
    {
        $bracket = BracketGenerator::generarBracket([1, 2, 3, 4]);

        $this->assertCount(3, $bracket); // 2 + 1
        $this->assertSame(1, $bracket[0]['round_number']);
        $this->assertSame(2, $bracket[2]['round_number']);

        // Semifinal 1 avanza a la final en slot a
        $this->assertSame(2, $bracket[0]['next_match_index']);
        $this->assertSame('a', $bracket[0]['next_slot']);
        // Semifinal 2 avanza a la final en slot b
        $this->assertSame(2, $bracket[1]['next_match_index']);
        $this->assertSame('b', $bracket[1]['next_slot']);

        $participantes = array_filter([
            $bracket[0]['participant_a_id'], $bracket[0]['participant_b_id'],
            $bracket[1]['participant_a_id'], $bracket[1]['participant_b_id'],
        ]);
        $this->assertCount(4, $participantes);
    }

    public function test_5_participantes_con_byes(): void
    {
        $bracket = BracketGenerator::generarBracket([1, 2, 3, 4, 5]);

        $this->assertCount(7, $bracket); // 4 + 2 + 1 (3 byes + 4 reales)
        $this->assertSame(1, $bracket[0]['round_number']);
        $this->assertSame(3, $bracket[count($bracket) - 1]['round_number']);
        $this->assertNull($bracket[count($bracket) - 1]['next_match_index']);

        // Byes: los primeros 3 enfrentamientos tienen b = null
        foreach ([0, 1, 2] as $i) {
            $this->assertNotNull($bracket[$i]['participant_a_id']);
            $this->assertNull($bracket[$i]['participant_b_id']);
        }
        // El partido real es el 4º de la primera ronda
        $this->assertNotNull($bracket[3]['participant_a_id']);
        $this->assertNotNull($bracket[3]['participant_b_id']);

        // Todos los next_match_index resuelven dentro del bracket
        foreach ($bracket as $i => $m) {
            if ($m['next_match_index'] !== null) {
                $this->assertArrayHasKey($m['next_match_index'], $bracket);
            }
        }
    }

    public function test_8_participantes_sin_byes(): void
    {
        $bracket = BracketGenerator::generarBracket(range(1, 8));
        $this->assertCount(7, $bracket);

        foreach (array_slice($bracket, 0, 4) as $m) {
            $this->assertNotNull($m['participant_a_id']);
            $this->assertNotNull($m['participant_b_id']);
        }
    }

    public function test_menos_de_2_no_genera(): void
    {
        $this->assertSame([], BracketGenerator::generarBracket([7]));
        $this->assertSame([], BracketGenerator::generarBracket([]));
    }

    public function test_asignar_grupos_equilibrados(): void
    {
        $grupos = BracketGenerator::asignarGrupos(range(1, 8), 2);
        $this->assertCount(2, $grupos);
        $this->assertSame('Grupo A', $grupos[0]['nombre']);
        $this->assertCount(4, $grupos[0]['participant_ids']);
        $this->assertCount(4, $grupos[1]['participant_ids']);

        $grupos = BracketGenerator::asignarGrupos(range(1, 5), 2);
        $this->assertCount(3, $grupos[0]['participant_ids']);
        $this->assertCount(2, $grupos[1]['participant_ids']);
    }

    public function test_total_partidos(): void
    {
        $this->assertSame(0, BracketGenerator::totalPartidos(1));
        $this->assertSame(4, BracketGenerator::totalPartidos(5));
        $this->assertSame(7, BracketGenerator::totalPartidos(8));
    }
}