<?php

namespace Apps\Tournaments\Services;

/**
 * Pure bracket generation (no database).
 * Standard single-elimination structure with byes:
 * - The first round is completed to the power of 2; byes are single-participant
 *   matches (direct advance), never empty matches.
 * - Each match references the next one (global next_index + a/b slot).
 */
class BracketGenerator
{
    /**
     * @param array $participantIds ids of tournament_participants (the order defines the seed)
     * @return array{round_number:int, match_number:int, participant_a_id:int|null,
     *               participant_b_id:int|null, next_match_index:int|null,
     *               next_slot:string|null}[]
     */
    public static function generateBracket(array $participantIds): array
    {
        $count = count($participantIds);
        if ($count < 2) {
            return [];
        }

        $capacity = 2 ** (int) ceil(log($count, 2));
        $byes = $capacity - $count;

        // Round 1: byes go first (high seeds pass directly)
        $line = array_values($participantIds);
        $round1Matches = [];
        $idx = 0;
        for ($pos = 0; $pos < $capacity / 2; $pos++) {
            if ($pos < $byes) {
                // Bye: a single participant
                $round1Matches[] = ['a' => $line[$idx] ?? null, 'b' => null];
                $idx++;
            } else {
                $round1Matches[] = ['a' => $line[$idx] ?? null, 'b' => $line[$idx + 1] ?? null];
                $idx += 2;
            }
        }

        $rounds = [1 => $round1Matches];
        $count = $capacity / 2;
        $round = 2;
        while ($count > 1) {
            $rounds[$round] = array_fill(0, (int) ($count / 2), ['a' => null, 'b' => null]);
            $count = (int) ($count / 2);
            $round++;
        }

        // Flatten and compute references to the next match
        $out = [];
        $total = 0;
        $roundCount = count($rounds);
        foreach ($rounds as $rn => $matches) {
            foreach ($matches as $i => $m) {
                $isLast = $rn === $roundCount;
                $out[] = [
                    'round_number' => $rn,
                    'match_number' => $i + 1,
                    'participant_a_id' => $m['a'],
                    'participant_b_id' => $m['b'],
                    // The next match lives after ALL of this round
                    'next_match_index' => $isLast ? null : $total + count($matches) + intdiv($i, 2),
                    'next_slot' => $isLast ? null : ($i % 2 === 0 ? 'a' : 'b'),
                ];
            }
            $total += count($matches);
        }

        return $out;
    }

    /**
     * Distributes participants into balanced groups.
     *
     * @return array{name:string, position:int, participant_ids:int[]}[]
     */
    public static function assignGroups(array $participantIds, int $numGroups): array
    {
        $numGroups = max(2, $numGroups);
        $count = count($participantIds);
        if ($count === 0) {
            return [];
        }

        $groups = [];
        for ($g = 0; $g < $numGroups; $g++) {
            $groups[] = ['name' => 'Grupo ' . chr(65 + $g), 'position' => $g + 1, 'participant_ids' => []];
        }

        foreach (array_values($participantIds) as $i => $id) {
            $groups[$i % $numGroups]['participant_ids'][] = $id;
        }

        return array_values(array_filter($groups, fn($g) => count($g['participant_ids']) > 0));
    }

    /**
     * Round-robin fixtures for a group (single round, circle method).
     * With an odd number of teams one rests per jornada (bye).
     *
     * @param array $participantIds ids of tournament_participants
     * @return array{round_number:int, match_number:int, participant_a_id:int|null, participant_b_id:int|null}[]
     *         `match_number` is global (consecutive across jornadas).
     */
    public static function generateRoundRobin(array $participantIds): array
    {
        $teams = array_values($participantIds);
        $count = count($teams);
        if ($count < 2) {
            return [];
        }

        // Odd count: add a dummy that rests (never paired with a real team twice).
        if ($count % 2 === 1) {
            $teams[] = null;
            $count++;
        }

        $jornadas = $count - 1;
        $out = [];
        $matchNumber = 0;
        for ($j = 0; $j < $jornadas; $j++) {
            $first = $teams[0];
            for ($i = 0; $i < $count / 2; $i++) {
                $a = $i === 0 ? $first : $teams[$i];
                $b = $teams[$count - 1 - $i];
                if ($a === null || $b === null) {
                    continue; // the resting team
                }
                $out[] = [
                    'round_number' => $j + 1,
                    'match_number' => ++$matchNumber,
                    'participant_a_id' => $a,
                    'participant_b_id' => $b,
                ];
            }
            // Rotate: keep first fixed, shift the rest.
            $teams = [$teams[0], ...array_slice($teams, -1), ...array_slice($teams, 1, -1)];
        }

        return $out;
    }

    /**
     * Number of matches a bracket with n participants will have (n - 1).
     */
    public static function totalMatches(int $n): int
    {
        return max(0, $n - 1);
    }
}