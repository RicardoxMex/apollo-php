<?php

namespace Tests\Unit\Tournaments;

use Apps\Tournaments\Services\BracketGenerator;
use PHPUnit\Framework\TestCase;

class BracketGeneratorTest extends TestCase
{
    public function test_2_participants_one_round(): void
    {
        $bracket = BracketGenerator::generateBracket([11, 22]);

        $this->assertCount(1, $bracket);
        $this->assertSame(1, $bracket[0]['round_number']);
        $this->assertSame(11, $bracket[0]['participant_a_id']);
        $this->assertSame(22, $bracket[0]['participant_b_id']);
        $this->assertNull($bracket[0]['next_match_index']);
        $this->assertNull($bracket[0]['next_slot']);
    }

    public function test_4_participants_no_byes_and_advances(): void
    {
        $bracket = BracketGenerator::generateBracket([1, 2, 3, 4]);

        $this->assertCount(3, $bracket); // 2 + 1
        $this->assertSame(1, $bracket[0]['round_number']);
        $this->assertSame(2, $bracket[2]['round_number']);

        // Semifinal 1 advances to the final in slot a
        $this->assertSame(2, $bracket[0]['next_match_index']);
        $this->assertSame('a', $bracket[0]['next_slot']);
        // Semifinal 2 advances to the final in slot b
        $this->assertSame(2, $bracket[1]['next_match_index']);
        $this->assertSame('b', $bracket[1]['next_slot']);

        $participants = array_filter([
            $bracket[0]['participant_a_id'], $bracket[0]['participant_b_id'],
            $bracket[1]['participant_a_id'], $bracket[1]['participant_b_id'],
        ]);
        $this->assertCount(4, $participants);
    }

    public function test_5_participants_with_byes(): void
    {
        $bracket = BracketGenerator::generateBracket([1, 2, 3, 4, 5]);

        $this->assertCount(7, $bracket); // 4 + 2 + 1 (3 byes + 4 reales)
        $this->assertSame(1, $bracket[0]['round_number']);
        $this->assertSame(3, $bracket[count($bracket) - 1]['round_number']);
        $this->assertNull($bracket[count($bracket) - 1]['next_match_index']);

        // Byes: the first 3 fixtures have b = null
        foreach ([0, 1, 2] as $i) {
            $this->assertNotNull($bracket[$i]['participant_a_id']);
            $this->assertNull($bracket[$i]['participant_b_id']);
        }
        // The real match is the 4th of the first round
        $this->assertNotNull($bracket[3]['participant_a_id']);
        $this->assertNotNull($bracket[3]['participant_b_id']);

        // All next_match_index resolve inside the bracket
        foreach ($bracket as $i => $m) {
            if ($m['next_match_index'] !== null) {
                $this->assertArrayHasKey($m['next_match_index'], $bracket);
            }
        }
    }

    public function test_8_participants_no_byes(): void
    {
        $bracket = BracketGenerator::generateBracket(range(1, 8));
        $this->assertCount(7, $bracket);

        foreach (array_slice($bracket, 0, 4) as $m) {
            $this->assertNotNull($m['participant_a_id']);
            $this->assertNotNull($m['participant_b_id']);
        }
    }

    public function test_less_than_2_does_not_generate(): void
    {
        $this->assertSame([], BracketGenerator::generateBracket([7]));
        $this->assertSame([], BracketGenerator::generateBracket([]));
    }

    public function test_assign_balanced_groups(): void
    {
        $groups = BracketGenerator::assignGroups(range(1, 8), 2);
        $this->assertCount(2, $groups);
        $this->assertSame('Grupo A', $groups[0]['name']);
        $this->assertCount(4, $groups[0]['participant_ids']);
        $this->assertCount(4, $groups[1]['participant_ids']);

        $groups = BracketGenerator::assignGroups(range(1, 5), 2);
        $this->assertCount(3, $groups[0]['participant_ids']);
        $this->assertCount(2, $groups[1]['participant_ids']);
    }

    public function test_total_matches(): void
    {
        $this->assertSame(0, BracketGenerator::totalMatches(1));
        $this->assertSame(4, BracketGenerator::totalMatches(5));
        $this->assertSame(7, BracketGenerator::totalMatches(8));
    }
}