<?php

namespace Apps\Tournaments\Services;

/**
 * Mapeos entre los valores de la API (frontend) y los ENUM de la base de datos.
 * El frontend usa español: 'eliminacion-directa', 'publico'…; la BD usa inglés.
 */
class Mapeos
{
    private const FORMATOS = [
        'eliminacion-directa' => 'single_elimination',
        'doble-eliminacion' => 'double_elimination',
        'round-robin' => 'round_robin',
        'grupos' => 'groups',
        'liga' => 'league',
    ];

    private const VISIBILIDAD = [
        'publico' => 'public',
        'privado' => 'private',
    ];

    public static function formatoDesdeApi(?string $valor): ?string
    {
        return $valor !== null ? (self::FORMATOS[$valor] ?? null) : null;
    }

    public static function formatoHaciaApi(?string $valor): ?string
    {
        if ($valor === null) {
            return null;
        }
        $api = array_search($valor, self::FORMATOS, true);
        return $api !== false ? $api : $valor;
    }

    public static function visibilidadDesdeApi(?string $valor): ?string
    {
        return $valor !== null ? (self::VISIBILIDAD[$valor] ?? null) : null;
    }

    public static function visibilidadHaciaApi(?string $valor): ?string
    {
        if ($valor === null) {
            return null;
        }
        $api = array_search($valor, self::VISIBILIDAD, true);
        return $api !== false ? $api : $valor;
    }
}