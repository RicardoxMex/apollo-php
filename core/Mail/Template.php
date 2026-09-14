<?php

namespace Apollo\Core\Mail;

/**
 * Plantillas de email: render simple de {{placeholders}} y registro central de
 * plantillas (subject / html / text) en Templates/registry.php.
 * Sin motor de plantillas externo (D1): sustitución de variables escalares.
 */
class Template
{
    /**
     * Sustituye {{clave}} (y rutas anidadas {{a.b}}) con $vars. Las claves
     * desconocidas o no escalares se reemplazan por cadena vacía.
     */
    public static function render(string $template, array $vars): string
    {
        return (string) preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', function (array $m) use ($vars) {
            $value = $vars;
            foreach (explode('.', $m[1]) as $part) {
                if (!is_array($value) || !array_key_exists($part, $value)) {
                    return '';
                }
                $value = $value[$part];
            }
            return is_scalar($value) || $value === null ? (string) $value : '';
        }, $template);
    }

    /** Registro global de plantillas: ['subject' => …, 'html' => …, 'text' => …]. */
    public static function get(string $name): ?array
    {
        $registry = require __DIR__ . '/Templates/registry.php';
        return $registry[$name] ?? null;
    }
}