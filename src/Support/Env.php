<?php

namespace ApolloPHP\Support;

class Env
{
    protected static array $variables = [];
    protected static bool $loaded = false;
    
    public static function load(string $path): void
    {
        if (static::$loaded) {
            return;
        }
        
        $envFile = $path . '/.env';
        
        if (!file_exists($envFile)) {
            return;
        }
        
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            // Ignorar comentarios
            if (strpos(trim($line), '#') === 0) {
                continue;
            }
            
            // Ignorar líneas sin =
            if (strpos($line, '=') === false) {
                continue;
            }
            
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            
            // Remover comillas
            if (preg_match('/^"(.*)"$/s', $value, $matches)) {
                $value = $matches[1];
            } elseif (preg_match("/^'(.*)'$/s", $value, $matches)) {
                $value = $matches[1];
            }
            
            // Expandir variables de entorno
            $value = static::expandVariables($value);
            
            static::$variables[$name] = $value;
            
            if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                putenv("$name=$value");
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
        
        static::$loaded = true;
    }
    
    protected static function expandVariables(string $value): string
    {
        return preg_replace_callback('/\${([a-zA-Z_][a-zA-Z0-9_]*)}/', function($matches) {
            $varName = $matches[1];
            return getenv($varName) ?: $matches[0];
        }, $value);
    }
    
    public static function get(string $key, $default = null)
    {
        $value = getenv($key);
        
        if ($value === false) {
            return $default;
        }
        
        switch (strtolower($value)) {
            case 'true':
            case '(true)':
                return true;
            case 'false':
            case '(false)':
                return false;
            case 'empty':
            case '(empty)':
                return '';
            case 'null':
            case '(null)':
                return null;
        }
        
        // Remove surrounding quotes
        if (strlen($value) > 1 && (
            ($value[0] === '"' && substr($value, -1) === '"') ||
            ($value[0] === "'" && substr($value, -1) === "'")
        )) {
            return substr($value, 1, -1);
        }
        
        return $value;
    }
    
    public static function set(string $key, string $value): void
    {
        static::$variables[$key] = $value;
        putenv("$key=$value");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
    
    public static function all(): array
    {
        return static::$variables;
    }
}