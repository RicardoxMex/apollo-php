<?php
// core/Validation/RuleRegistry.php

namespace Apollo\Core\Validation;

use Apollo\Core\Database\Connection\DatabaseManager;
use Apollo\Core\Uploads\Support\UploadedFile;
use DateTime;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Registro de reglas de validación del framework.
 *
 * Cada regla es una pareja [callable, plantilla de mensaje]. El callable recibe
 * ($valor, $params, $datos, $campo) y devuelve true si el valor es válido.
 *
 * Reglas nuevas se pueden registrar por instancia con register() o desde un
 * Validator con Validator::extend().
 */
class RuleRegistry
{
    /** @var array<string, array{0: callable, 1: string}> */
    private array $rules = [];

    public function __construct()
    {
        $this->registerDefaults();
    }

    /**
     * Registrar (o sobreescribir) una regla personalizada.
     *
     * @param  callable  $rule  fn($value, array $params, array $data, string $field): bool
     */
    public function register(string $name, callable $rule, string $message): void
    {
        $this->rules[$name] = [$rule, $message];
    }

    public function has(string $name): bool
    {
        return isset($this->rules[$name]);
    }

    /**
     * @return array{0: callable, 1: string}
     */
    public function get(string $name): array
    {
        if (!$this->has($name)) {
            throw new InvalidArgumentException("Regla de validación desconocida: {$name}");
        }

        return $this->rules[$name];
    }

    private function registerDefaults(): void
    {
        // ── Presencia ──────────────────────────────────────────────────────
        $this->register(
            'required',
            fn ($value) => $value !== null
                && $value !== ''
                && (!is_array($value) || count($value) > 0),
            'El campo :field es obligatorio.'
        );

        // ── Tipos ──────────────────────────────────────────────────────────
        $this->register(
            'email',
            fn ($value) => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'El campo :field debe ser un email válido.'
        );

        $this->register(
            'string',
            fn ($value) => is_string($value),
            'El campo :field debe ser una cadena de texto.'
        );

        $this->register(
            'numeric',
            fn ($value) => is_numeric($value),
            'El campo :field debe ser numérico.'
        );

        $this->register(
            'integer',
            fn ($value) => filter_var($value, FILTER_VALIDATE_INT) !== false,
            'El campo :field debe ser un número entero.'
        );

        $this->register(
            'boolean',
            fn ($value) => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== null,
            'El campo :field debe ser verdadero o falso.'
        );

        $this->register(
            'array',
            fn ($value) => is_array($value),
            'El campo :field debe ser un array.'
        );

        $this->register(
            'json',
            function ($value) {
                if (!is_string($value)) {
                    return false;
                }

                json_decode($value);

                return json_last_error() === JSON_ERROR_NONE;
            },
            'El campo :field debe ser un JSON válido.'
        );

        $this->register(
            'url',
            fn ($value) => is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false,
            'El campo :field debe ser una URL válida.'
        );

        $this->register(
            'ip',
            fn ($value) => is_string($value) && filter_var($value, FILTER_VALIDATE_IP) !== false,
            'El campo :field debe ser una dirección IP válida.'
        );

        $this->register(
            'alpha',
            fn ($value) => is_string($value) && ctype_alpha($value),
            'El campo :field solo puede contener letras.'
        );

        $this->register(
            'alpha_num',
            fn ($value) => is_string($value) && ctype_alnum($value),
            'El campo :field solo puede contener letras y números.'
        );

        // ── Fechas ─────────────────────────────────────────────────────────
        $this->register(
            'date',
            fn ($value) => $value instanceof DateTimeInterface
                || (is_string($value) && strtotime($value) !== false),
            'El campo :field debe ser una fecha válida.'
        );

        $this->register(
            'date_format',
            function ($value, array $params) {
                $format = $params[0] ?? null;

                if ($format === null || !is_string($value)) {
                    return false;
                }

                $date = DateTime::createFromFormat($format, $value);

                // Round-trip: evita que "2024-13-45" pase como Y-m-d (rollover)
                return $date !== false && $date->format($format) === $value;
            },
            'El campo :field no coincide con el formato :param.'
        );

        // ── Tamaños (string: longitud, número: valor, array: count,
        //    archivo: tamaño en KB) ───────────────────────────────────────
        $fileSizeKb = function ($value) {
            if ($value instanceof UploadedFile) {
                return $value->size() / 1024;
            }

            if (is_array($value) && isset($value['size']) && is_numeric($value['size'])) {
                return (float) $value['size'] / 1024;
            }

            return null;
        };

        $span = function ($value) use ($fileSizeKb) {
            $kb = $fileSizeKb($value);

            if ($kb !== null) {
                return $kb;
            }

            if (is_array($value)) {
                return count($value);
            }

            if (is_numeric($value)) {
                return (float) $value;
            }

            if ($value instanceof \Countable) {
                return count($value);
            }

            return mb_strlen((string) $value);
        };

        $num = fn ($n) => (float) $n;

        $this->register(
            'min',
            function ($value, array $params) use ($span, $num) {
                $min = $params[0] ?? null;

                return $min !== null && $span($value) >= $num($min);
            },
            'El campo :field debe ser mayor o igual a :param.'
        );

        $this->register(
            'max',
            function ($value, array $params) use ($span, $num) {
                $max = $params[0] ?? null;

                return $max !== null && $span($value) <= $num($max);
            },
            'El campo :field debe ser menor o igual a :param.'
        );

        $this->register(
            'between',
            function ($value, array $params) use ($span, $num) {
                $min = $params[0] ?? null;
                $max = $params[1] ?? null;

                return $min !== null
                    && $max !== null
                    && $span($value) >= $num($min)
                    && $span($value) <= $num($max);
            },
            'El campo :field debe estar entre :param1 y :param2.'
        );

        $this->register(
            'size',
            function ($value, array $params) use ($span, $num) {
                $size = $params[0] ?? null;

                return $size !== null && $span($value) == $num($size);
            },
            'El campo :field debe tener exactamente :param.'
        );

        // ── Listas ─────────────────────────────────────────────────────────
        $asStrings = function ($value) use ($span) {
            if (is_array($value)) {
                return false;
            }

            return (string) $value;
        };

        $this->register(
            'in',
            function ($value, array $params) use ($asStrings) {
                $value = $asStrings($value);

                if ($value === false) {
                    return false;
                }

                return in_array($value, array_map('strval', $params), true);
            },
            'El campo :field debe ser uno de: :params.'
        );

        $this->register(
            'not_in',
            function ($value, array $params) use ($asStrings) {
                $value = $asStrings($value);

                if ($value === false) {
                    return false;
                }

                return !in_array($value, array_map('strval', $params), true);
            },
            'El campo :field no puede ser uno de: :params.'
        );

        // ── Comparaciones entre campos ─────────────────────────────────────
        $this->register(
            'same',
            function ($value, array $params, array $data) {
                $other = $params[0] ?? null;

                if ($other === null || !array_key_exists($other, $data)) {
                    return false;
                }

                if (is_array($value) && is_array($data[$other])) {
                    return $value == $data[$other];
                }

                return (string) $value === (string) $data[$other];
            },
            'El campo :field debe coincidir con :param.'
        );

        $this->register(
            'different',
            function ($value, array $params, array $data) {
                $other = $params[0] ?? null;

                if ($other === null || !array_key_exists($other, $data)) {
                    return true;
                }

                if (is_array($value) && is_array($data[$other])) {
                    return $value != $data[$other];
                }

                return (string) $value !== (string) $data[$other];
            },
            'El campo :field debe ser diferente de :param.'
        );

        $this->register(
            'confirmed',
            function ($value, array $params, array $data, string $field) {
                return array_key_exists($field . '_confirmation', $data)
                    && (string) $value === (string) $data[$field . '_confirmation'];
            },
            'La confirmación de :field no coincide.'
        );

        // ── Archivos (subida vía $_FILES o UploadedFile) ─────────────────
        $this->register(
            'file',
            function ($value) {
                if ($value instanceof UploadedFile) {
                    return $value->isValid();
                }

                if (!is_array($value) || !isset($value['tmp_name'], $value['error'])) {
                    return false;
                }

                return (int) $value['error'] === UPLOAD_ERR_OK
                    && is_file($value['tmp_name']);
            },
            'El campo :field debe ser un archivo válido.'
        );

        $this->register(
            'mimes',
            function ($value, array $params) {
                $extension = $this->extensionOf($value);

                if ($extension === null) {
                    return false;
                }

                $allowed = array_map('strtolower', $params);

                return in_array($extension, $allowed, true);
            },
            'El campo :field debe ser un archivo de tipo: :params.'
        );

        $this->register(
            'image',
            function ($value) {
                $mime = $this->mimeOf($value);

                return $mime !== null && str_starts_with($mime, 'image/');
            },
            'El campo :field debe ser una imagen.'
        );

        // ── Patrón ─────────────────────────────────────────────────────────
        $this->register(
            'regex',
            function ($value, array $params) {
                $pattern = $params[0] ?? null;
                $value = (string) $value;

                return $pattern !== null && @preg_match($pattern, $value) === 1;
            },
            'El campo :field no cumple el formato requerido.'
        );

        // ── Base de datos (requieren conexión configurada) ─────────────────
        $dbRule = function (string $ruleName, bool $expectsExists) {
            return function ($value, array $params, array $data, string $field) use ($ruleName, $expectsExists) {
                $table = $params[0] ?? null;
                $column = $params[1] ?? $field;

                if ($table === null || $table === '') {
                    throw new InvalidArgumentException(
                        "La regla {$ruleName} requiere tabla y columna: {$ruleName}:tabla,columna[,idExcepcion]"
                    );
                }

                $pdo = DatabaseManager::getConnection();

                // Tabla/columna son proporcionadas por el desarrollador, no por el cliente
                $sql = "SELECT COUNT(*) FROM {$table} WHERE {$column} = :value";
                $bindings = [':value' => $value];

                if (isset($params[2]) && $params[2] !== '') {
                    $ignoreColumn = $params[3] ?? 'id';
                    $sql .= " AND {$ignoreColumn} != :ignore";
                    $bindings[':ignore'] = $params[2];
                }

                $stmt = $pdo->prepare($sql);
                $stmt->execute($bindings);
                $count = (int) $stmt->fetchColumn();

                return $expectsExists ? $count > 0 : $count === 0;
            };
        };

        $this->register(
            'unique',
            $dbRule('unique', false),
            'El valor de :field ya está en uso.'
        );

        $this->register(
            'exists',
            $dbRule('exists', true),
            'El valor de :field no existe.'
        );
    }

    /**
     * Extensión del archivo (minúscula) para arrays de $_FILES o UploadedFile.
     */
    private function extensionOf(mixed $value): ?string
    {
        if ($value instanceof UploadedFile) {
            $extension = $value->extension();

            return $extension === '' ? null : $extension;
        }

        if (!is_array($value) || !isset($value['name']) || !is_string($value['name'])) {
            return null;
        }

        $extension = strtolower(pathinfo($value['name'], PATHINFO_EXTENSION));

        return $extension === '' ? null : $extension;
    }

    /**
     * MIME del archivo: sniff real para UploadedFile (o type declarado),
     * type declarado para arrays de $_FILES.
     */
    private function mimeOf(mixed $value): ?string
    {
        if ($value instanceof UploadedFile) {
            return $value->mime();
        }

        if (!is_array($value) || !isset($value['type']) || !is_string($value['type'])) {
            return null;
        }

        return $value['type'];
    }
}