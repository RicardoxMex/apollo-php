<?php
// core/Validation/ValidationException.php

namespace Apollo\Core\Validation;

use InvalidArgumentException;

/**
 * Excepción lanzada por Validator::validateOrFail() cuando los datos no pasan.
 * Los errores quedan agrupados por campo para devolverlos en una respuesta 422.
 */
class ValidationException extends InvalidArgumentException
{
    /** @var array<string, list<string>> */
    private array $errors;

    public function __construct(array $errors, string $message = 'Los datos enviados no son válidos.')
    {
        parent::__construct($message);
        $this->errors = $errors;
    }

    /**
     * Errores agrupados por campo: ['email' => ['El campo email debe ser un email válido.']]
     *
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Todos los mensajes en una sola lista (sin agrupar por campo).
     *
     * @return list<string>
     */
    public function errorsAll(): array
    {
        $all = [];

        foreach ($this->errors as $messages) {
            foreach ($messages as $message) {
                $all[] = $message;
            }
        }

        return $all;
    }
}