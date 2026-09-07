<?php

namespace Apps\Tournaments\Services;

/**
 * Mappings between the API values (frontend) and the database ENUMs.
 * The frontend uses Spanish: 'eliminacion-directa', 'publico'...; the DB uses English.
 */
class Mappings
{
    private const FORMATS = [
        'eliminacion-directa' => 'single_elimination',
        'doble-eliminacion' => 'double_elimination',
        'round-robin' => 'round_robin',
        'grupos' => 'groups',
        'liga' => 'league',
    ];

    private const VISIBILITIES = [
        'publico' => 'public',
        'privado' => 'private',
    ];

    public static function formatFromApi(?string $value): ?string
    {
        return $value !== null ? (self::FORMATS[$value] ?? null) : null;
    }

    public static function formatToApi(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $api = array_search($value, self::FORMATS, true);
        return $api !== false ? $api : $value;
    }

    public static function visibilityFromApi(?string $value): ?string
    {
        return $value !== null ? (self::VISIBILITIES[$value] ?? null) : null;
    }

    public static function visibilityToApi(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $api = array_search($value, self::VISIBILITIES, true);
        return $api !== false ? $api : $value;
    }
}