<?php

namespace Apps\Tournaments\Services;

/**
 * Slugs de torneos (espejo de modules/mis-torneos/lib/slug.ts del frontend):
 * "Copa Primavera 2026" → "copa-primavera-2026". Sin acentos (mapa
 * determinista, sin dependencias de iconv/intl), guiones colapsados, máx. 60.
 */
class Slugs
{
    private const MAP = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'ā' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ō' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ū' => 'u',
        'ñ' => 'n', 'ç' => 'c', 'ý' => 'y', 'ÿ' => 'y',
        'æ' => 'ae', 'œ' => 'oe', 'ß' => 'ss',
    ];

    public static function from(string $title): string
    {
        $clean = strtr(mb_strtolower($title), self::MAP);
        $clean = (string) preg_replace('/[^a-z0-9]+/', '-', $clean);
        $clean = trim($clean, '-');
        return mb_substr($clean === '' ? 'torneo' : $clean, 0, 60);
    }
}