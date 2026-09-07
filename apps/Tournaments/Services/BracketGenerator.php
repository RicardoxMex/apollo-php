<?php

namespace Apps\Tournaments\Services;

/**
 * Generación de brackets pura (sin base de datos).
 * Estructura estándar de eliminación directa con byes:
 * - La primera ronda se completa a la potencia de 2; los byes son matches de un
 *   solo participante (avance directo), nunca matches vacíos.
 * - Cada match referencia al siguiente (next_index global + slot a/b).
 */
class BracketGenerator
{
    /**
     * @param array $participantIds ids de tournament_participants (el orden define el seed)
     * @return array{round_number:int, match_number:int, participant_a_id:int|null,
     *               participant_b_id:int|null, next_match_index:int|null,
     *               next_slot:string|null}[]
     */
    public static function generarBracket(array $participantIds): array
    {
        $n = count($participantIds);
        if ($n < 2) {
            return [];
        }

        $capacidad = 2 ** (int) ceil(log($n, 2));
        $byes = $capacidad - $n;

        // Ronda 1: los byes van primero (seeds altos pasan directo)
        $fila = array_values($participantIds);
        $matchesRonda1 = [];
        $idx = 0;
        for ($pos = 0; $pos < $capacidad / 2; $pos++) {
            if ($pos < $byes) {
                // Bye: un solo participante
                $matchesRonda1[] = ['a' => $fila[$idx] ?? null, 'b' => null];
                $idx++;
            } else {
                $matchesRonda1[] = ['a' => $fila[$idx] ?? null, 'b' => $fila[$idx + 1] ?? null];
                $idx += 2;
            }
        }

        $rondas = [1 => $matchesRonda1];
        $count = $capacidad / 2;
        $ronda = 2;
        while ($count > 1) {
            $rondas[$ronda] = array_fill(0, (int) ($count / 2), ['a' => null, 'b' => null]);
            $count = (int) ($count / 2);
            $ronda++;
        }

        // Aplanar y calcular referencias al siguiente match
        $out = [];
        $total = 0;
        $numRondas = count($rondas);
        foreach ($rondas as $rn => $matches) {
            foreach ($matches as $i => $m) {
                $esUltima = $rn === $numRondas;
                $out[] = [
                    'round_number' => $rn,
                    'match_number' => $i + 1,
                    'participant_a_id' => $m['a'],
                    'participant_b_id' => $m['b'],
                    // El siguiente match vive después de TODA esta ronda
                    'next_match_index' => $esUltima ? null : $total + count($matches) + intdiv($i, 2),
                    'next_slot' => $esUltima ? null : ($i % 2 === 0 ? 'a' : 'b'),
                ];
            }
            $total += count($matches);
        }

        return $out;
    }

    /**
     * Distribuye participantes en grupos equilibrados.
     *
     * @return array{nombre:string, position:int, participant_ids:int[]}[]
     */
    public static function asignarGrupos(array $participantIds, int $numGrupos): array
    {
        $numGrupos = max(2, $numGrupos);
        $n = count($participantIds);
        if ($n === 0) {
            return [];
        }

        $grupos = [];
        for ($g = 0; $g < $numGrupos; $g++) {
            $grupos[] = ['nombre' => 'Grupo ' . chr(65 + $g), 'position' => $g + 1, 'participant_ids' => []];
        }

        foreach (array_values($participantIds) as $i => $id) {
            $grupos[$i % $numGrupos]['participant_ids'][] = $id;
        }

        return array_values(array_filter($grupos, fn($g) => count($g['participant_ids']) > 0));
    }

    /**
     * Número de partidos que tendrá un bracket con n participantes (n - 1).
     */
    public static function totalPartidos(int $n): int
    {
        return max(0, $n - 1);
    }
}