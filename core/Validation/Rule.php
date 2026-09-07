<?php
// core/Validation/Rule.php

namespace Apollo\Core\Validation;

/**
 * Contrato para reglas de validación personalizadas.
 *
 * Una regla personalizada puede usarse directamente en el array de reglas:
 *
 *     Validator::make($data, ['codigo' => new CodigoUnicoRule()]);
 */
interface Rule
{
    /**
     * Determina si el valor pasa la regla.
     *
     * @param  string  $field  Nombre del campo validado
     * @param  mixed   $value  Valor a validar
     * @param  array<string, mixed>  $data  Todos los datos del formulario/payload
     */
    public function passes(string $field, mixed $value, array $data): bool;

    /**
     * Mensaje de error cuando la regla falla.
     */
    public function message(string $field, mixed $value): string;
}