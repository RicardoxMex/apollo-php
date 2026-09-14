<?php

namespace Apps\Tournaments\Services;

/**
 * Pure business rules of the tournaments domain (no database).
 * Mirror of the frontend rules: lib/ciclo.ts and lib/edicion.ts.
 */
class TournamentRules
{
    /**
     * Transition draft → open.
     * Public tournaments have no minimum team requirement; private ones need ≥ 2 accepted.
     */
    public static function canPublish(array $tournament): array
    {
        if (($tournament['status'] ?? '') !== 'draft') {
            return ['ok' => false, 'reason' => 'Solo se pueden publicar torneos en borrador'];
        }

        if (($tournament['visibility'] ?? 'public') === 'private') {
            $accepted = (int) ($tournament['aceptados'] ?? 0);
            if ($accepted < 2) {
                return ['ok' => false, 'reason' => 'Para publicar un torneo privado necesitas al menos 2 equipos inscritos'];
            }
        }

        return ['ok' => true];
    }

    /**
     * Transition open → live.
     *  - round-robin / liga: el "sorteo" es el calendario de jornadas generado
     *    directamente (sin draw); basta con que exista al menos un partido.
     *  - resto de formatos (eliminacion directa, doble eliminacion, grupos):
     *    exige un draw generado.
     */
    public static function canStart(array $tournament): array
    {
        if (($tournament['status'] ?? '') !== 'open') {
            return ['ok' => false, 'reason' => 'Solo se pueden iniciar torneos abiertos a inscripciones'];
        }

        // Acepta tanto el valor del front (round-robin) como el del DB ENUM (round_robin),
        // porque algunos call sites pasan el formato ya mapeado y otros no.
        $formato = $tournament['format'] ?? '';
        $esFormatoJornadas = in_array($formato, ['round-robin', 'round_robin', 'liga', 'league'], true);

        if ($esFormatoJornadas) {
            if (empty($tournament['tiene_partidos'])) {
                return ['ok' => false, 'reason' => 'Genera las jornadas antes de iniciar el torneo'];
            }
        } else {
            if (empty($tournament['tiene_draw'])) {
                return ['ok' => false, 'reason' => 'Genera el sorteo antes de iniciar el torneo'];
            }
        }

        return ['ok' => true];
    }

    /**
     * Transition live → finished. Requires the final (last round) to have a winner.
     */
    public static function canFinish(array $tournament): array
    {
        if (($tournament['status'] ?? '') !== 'live') {
            return ['ok' => false, 'reason' => 'Solo se pueden finalizar torneos en vivo'];
        }

        if (empty($tournament['final_con_ganador'])) {
            return ['ok' => false, 'reason' => 'La final debe tener un ganador antes de finalizar el torneo'];
        }

        return ['ok' => true];
    }

    /**
     * Editable fields depending on the state (mirror of lib/edicion.ts):
     * - draft: all
     * - open: structural fields locked (sport, format, max_participants,
     *          players_per_team, is_individual)
     * - live/finished: no editing
     */
    public static function editableFields(string $status): array
    {
        if (in_array($status, ['live', 'finished'], true)) {
            return [];
        }

        if ($status === 'open') {
            return [
                'title', 'description', 'location', 'image', 'start_date', 'end_date',
                'registration_deadline', 'registration_fee', 'currency', 'visibility',
                'minimum_age', 'rules', 'max_substitutes', 'season_id',
            ];
        }

        return [
            'title', 'sport', 'description', 'location', 'is_online', 'image', 'status',
            'format', 'max_participants', 'clasificados_eliminacion', 'ida_vuelta', 'is_individual', 'start_date', 'end_date',
            'registration_deadline', 'registration_fee', 'currency', 'visibility',
            'minimum_age', 'rules', 'max_substitutes', 'players_per_team', 'season_id',
            'stats', 'prizes',
        ];
    }

    /**
     * Keeps only the fields allowed for the current state.
     */
    public static function filterEditableFields(string $status, array $data): array
    {
        $allowed = self::editableFields($status);
        $filtered = array_intersect_key($data, array_flip($allowed));

        if (isset($filtered['visibility'])) {
            $filtered['visibility'] = Mappings::visibilityFromApi($filtered['visibility']) ?? $filtered['visibility'];
        }
        if (isset($filtered['format'])) {
            $filtered['format'] = Mappings::formatFromApi($filtered['format']) ?? $filtered['format'];
        }

        return $filtered;
    }

    /**
     * Validates a registration request (pure rules).
     * $alreadyRegistered: there is a pending or accepted record for the same participant.
     * $isFull: count(participants) >= max_participants.
     * $isOrganizer: the organizer can register directly in draft;
     * the public can only apply when the tournament is open.
     */
    public static function validateRegistration(array $tournament, ?int $teamId, ?int $playerId, bool $alreadyRegistered, bool $isFull, bool $isOrganizer = false): array
    {
        $status = $tournament['status'] ?? '';
        if ($status === 'open') {
            // the rest of the validations apply the same
        } elseif ($status === 'draft' && $isOrganizer) {
            // direct registration of the organizer in draft
        } else {
            return ['ok' => false, 'reason' => 'El torneo no está abierto a inscripciones'];
        }

        $individual = (bool) ($tournament['is_individual'] ?? false);
        if ($individual && $playerId === null) {
            return ['ok' => false, 'reason' => 'Este torneo es individual: inscribe a un jugador'];
        }
        if (!$individual && $teamId === null) {
            return ['ok' => false, 'reason' => 'Este torneo es por equipos: inscribe a un equipo'];
        }
        if ($teamId !== null && $playerId !== null) {
            return ['ok' => false, 'reason' => 'La solicitud debe ser de un equipo O de un jugador, no ambos'];
        }

        if ($alreadyRegistered) {
            return ['ok' => false, 'reason' => 'Este participante ya tiene una solicitud o está inscrito'];
        }

        if ($isFull) {
            return ['ok' => false, 'reason' => 'El torneo alcanzó su cupo máximo'];
        }

        if (!empty($tournament['registration_deadline']) && strtotime($tournament['registration_deadline']) < time()) {
            return ['ok' => false, 'reason' => 'El plazo de inscripción terminó'];
        }

        return ['ok' => true];
    }

    /**
     * Moderation decision validation (accept/reject).
     */
    public static function canDecide(string $currentStatus, string $action): array
    {
        if ($currentStatus !== 'pending') {
            return ['ok' => false, 'reason' => "La solicitud ya fue decidida ({$currentStatus})"];
        }
        if (!in_array($action, ['accepted', 'rejected'], true)) {
            return ['ok' => false, 'reason' => 'Acción inválida: usa accepted o rejected'];
        }
        return ['ok' => true];
    }

    /**
     * El número de clasificados a eliminación directa debe formar un bracket
     * COMPLETO: potencia de 2 (2, 4, 8, 16…) para que ningún equipo se quede
     * sin jornada en el cuadro final. 0 = sin fase final (válido).
     */
    public static function esBracketCompleto(int $clasificados): bool
    {
        if ($clasificados === 0) {
            return true;
        }
        return $clasificados >= 2 && ($clasificados & ($clasificados - 1)) === 0;
    }
}