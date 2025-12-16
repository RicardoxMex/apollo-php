<?php
use Apollo\Core\Application;
use Apollo\Core\Container\Container;

if (!function_exists('env')) {
    function env($key, $default = null)
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

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

        if (preg_match('/^"(.*)"$/', $value, $matches)) {
            return $matches[1];
        }

        return $value;
    }
}

if (!function_exists('dd')) {
    function dd(...$vars)
    {
        echo '<style>
            .dd-container {
                background: #1e1e1e;
                color: #f8f8f2;
                font-family: "Fira Code", "Monaco", "Consolas", monospace;
                font-size: 14px;
                line-height: 1.4;
                padding: 20px;
                margin: 10px 0;
                border-radius: 8px;
                box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
                overflow-x: auto;
            }
            .dd-container pre {
                margin: 0;
                white-space: pre-wrap;
                word-wrap: break-word;
            }
            .dd-header {
                background: #ff6b6b;
                color: white;
                padding: 8px 16px;
                margin: -20px -20px 15px -20px;
                border-radius: 8px 8px 0 0;
                font-weight: bold;
                font-size: 16px;
            }
        </style>';

        foreach ($vars as $index => $var) {
            echo '<div class="dd-container">';
            echo '<div class="dd-header">Variable #' . ($index + 1) . '</div>';
            echo '<pre>';
            var_dump($var);
            echo '</pre>';
            echo '</div>';
        }
        die();
    }
}


if (!function_exists('app')) {
    function app($abstract = null, array $parameters = [])
    {
        if (is_null($abstract)) {
            // Primero intentar obtener la instancia de Application
            if (Container::getInstance() instanceof Application) {
                return Container::getInstance();
            }

            // Si no hay Application, retornar Container
            return Container::getInstance();
        }

        return Container::getInstance()->make($abstract, $parameters);
    }
}

if (!function_exists('config')) {
    function config($key = null, $default = null)
    {
        if (is_null($key)) {
            return app('config');
        }

        return app('config')->get($key, $default);
    }
}

if (!function_exists('route')) {
    function route($name, $parameters = [])
    {
        return app('router')->url($name, $parameters);
    }
}

if (!function_exists('env')) {
    function env($key, $default = null)
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

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

        if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
            return substr($value, 1, -1);
        }

        return $value;
    }
}


if (!function_exists('request')) {
    /**
     * Obtener la instancia actual de Request
     */
    function request()
    {
        return app('request');
    }
}

if (!function_exists('response')) {
    /**
     * Crear una respuesta JSON
     */
    function response($data = null, $status = 200, $headers = [])
    {
        if (is_null($data)) {
            return app('response');
        }

        return \Apollo\Core\Http\Response::json($data, $status, $headers);
    }
}