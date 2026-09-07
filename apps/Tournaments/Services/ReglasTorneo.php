<?php

namespace Apps\Tournaments\Services;

/**
 * Reglas de negocio puras del dominio de torneos (sin base de datos).
 * Espejo de las reglas del frontend: lib/ciclo.ts y lib/edicion.ts.
 */
class ReglasTorneo
{
    /**
     * Transición draft → open.
     * El torneo público no exige mínimo de equipos; el privado requiere ≥ 2 aceptados.
     */
    public static function puedePublicar(array $torneo): array
    {
        if (($torneo['status'] ?? '') !== 'draft') {
            return ['ok' => false, 'motivo' => 'Solo se pueden publicar torneos en borrador'];
        }

        if (($torneo['visibility'] ?? 'public') === 'private') {
            $aceptados = (int) ($torneo['aceptados'] ?? 0);
            if ($aceptados < 2) {
                return ['ok' => false, 'motivo' => 'Para publicar un torneo privado necesitas al menos 2 equipos inscritos'];
            }
        }

        return ['ok' => true];
    }

    /**
     * Transición open → live. Requiere sorteo generado.
     */
    public static function puedeIniciar(array $torneo): array
    {
        if (($torneo['status'] ?? '') !== 'open') {
            return ['ok' => false, 'motivo' => 'Solo se pueden iniciar torneos abiertos a inscripciones'];
        }

        if (empty($torneo['tiene_draw'])) {
            return ['ok' => false, 'motivo' => 'Genera el sorteo antes de iniciar el torneo'];
        }

        return ['ok' => true];
    }

    /**
     * Transición live → finished. Requiere final (última ronda) con ganador.
     */
    public static function puedeFinalizar(array $torneo): array
    {
        if (($torneo['status'] ?? '') !== 'live') {
            return ['ok' => false, 'motivo' => 'Solo se pueden finalizar torneos en vivo'];
        }

        if (empty($torneo['final_con_ganador'])) {
            return ['ok' => false, 'motivo' => 'La final debe tener un ganador antes de finalizar el torneo'];
        }

        return ['ok' => true];
    }

    /**
     * Campos editables según el estado (espejo de lib/edicion.ts):
     * - draft: todos
     * - open: estructurales bloqueados (sport, format, max_participants, is_individual)
     * - live/finished: sin edición
     */
    public static function camposEditables(string $estado): array
    {
        if (in_array($estado, ['live', 'finished'], true)) {
            return [];
        }

        if ($estado === 'open') {
            return [
                'title', 'description', 'location', 'image', 'start_date', 'end_date',
                'registration_deadline', 'registration_fee', 'currency', 'visibility',
                'minimum_age', 'rules', 'max_substitutes', 'season_id',
            ];
        }

        return [
            'title', 'sport', 'description', 'location', 'is_online', 'image', 'status',
            'format', 'max_participants', 'is_individual', 'start_date', 'end_date',
            'registration_deadline', 'registration_fee', 'currency', 'visibility',
            'minimum_age', 'rules', 'max_substitutes', 'season_id',
        ];
    }

    /**
     * Deja solo los campos permitidos para el estado actual.
     */
    public static function filtrarEditables(string $estado, array $data): array
    {
        $permitidos = self::camposEditables($estado);
        $filtrados = array_intersect_key($data, array_flip($permitidos));

        if (isset($filtrados['visibility'])) {
            $filtrados['visibility'] = Mapeos::visibilidadDesdeApi($filtrados['visibility']) ?? $filtrados['visibility'];
        }
        if (isset($filtrados['format'])) {
            $filtrados['format'] = Mapeos::formatoDesdeApi($filtrados['format']) ?? $filtrados['format'];
        }

        return $filtrados;
    }

    /**
     * Valida una solicitud de inscripción (reglas puras).
     * $yaInscrito: existe registro pendiente o aceptado para el mismo participante.
     * $cupoLleno: count(participants) >= max_participants.
     * $esOrganizador: el organizador puede inscribir directamente en borrador (draft);
     * el público solo aplica en open.
     */
    public static function validarSolicitud(array $torneo, ?int $teamId, ?int $playerId, bool $yaInscrito, bool $cupoLleno, bool $esOrganizador = false): array
    {
        $estado = $torneo['status'] ?? '';
        if ($estado === 'open') {
            // resto de validaciones aplican igual
        } elseif ($estado === 'draft' && $esOrganizador) {
            // inscripción directa del organizador en borrador
        } else {
            return ['ok' => false, 'motivo' => 'El torneo no está abierto a inscripciones'];
        }

        $individual = (bool) ($torneo['is_individual'] ?? false);
        if ($individual && $playerId === null) {
            return ['ok' => false, 'motivo' => 'Este torneo es individual: inscribe a un jugador'];
        }
        if (!$individual && $teamId === null) {
            return ['ok' => false, 'motivo' => 'Este torneo es por equipos: inscribe a un equipo'];
        }
        if ($teamId !== null && $playerId !== null) {
            return ['ok' => false, 'motivo' => 'La solicitud debe ser de un equipo O de un jugador, no ambos'];
        }

        if ($yaInscrito) {
            return ['ok' => false, 'motivo' => 'Este participante ya tiene una solicitud o está inscrito'];
        }

        if ($cupoLleno) {
            return ['ok' => false, 'motivo' => 'El torneo alcanzó su cupo máximo'];
        }

        if (!empty($torneo['registration_deadline']) && strtotime($torneo['registration_deadline']) < time()) {
            return ['ok' => false, 'motivo' => 'El plazo de inscripción terminó'];
        }

        return ['ok' => true];
    }

    /**
     * Validación de decisión de moderación (aceptar/rechazar).
     */
    public static function puedeDecidir(string $estadoActual, string $accion): array
    {
        if ($estadoActual !== 'pending') {
            return ['ok' => false, 'motivo' => "La solicitud ya fue decidida ({$estadoActual})"];
        }
        if (!in_array($accion, ['accepted', 'rejected'], true)) {
            return ['ok' => false, 'motivo' => 'Acción inválida: usa accepted o rejected'];
        }
        return ['ok' => true];
    }
}