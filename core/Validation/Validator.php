<?php
// core/Validation/Validator.php

namespace Apollo\Core\Validation;

use Closure;
use InvalidArgumentException;

/**
 * Motor de validación del framework (inspirado en la validación de Laravel/DRF).
 *
 * Sintaxis de reglas:
 *
 *     Validator::make($data, [
 *         'titulo'   => 'required|string|min:3|max:120',
 *         'email'    => ['required', 'email', 'unique:usuarios,email'],
 *         'estado'   => 'in:draft,open,finished',
 *         'reglas'   => 'nullable|string',
 *         'password' => 'required|confirmed',          // requiere password_confirmation
 *         'codigo'   => new CodigoUnicoRule(),          // objeto Rule
 *         'slug'     => fn ($value) => strlen($value) > 2, // closure
 *     ], [
 *         'titulo.required' => 'El título es obligatorio.',
 *     ]);
 *
 * Modificadores: `sometimes` (solo si el campo está presente), `nullable`
 * (vacío/nulo pasa), `bail` (detener en el primer error del campo).
 * Reglas con parámetros: `min:3`, `between:18,65`, `in:a,b,c`...
 * Nota: para regex con `|` o `,` usa sintaxis de array (el pipe separa reglas).
 */
class Validator
{
    /** @var array<string, mixed> */
    private array $data;

    /** @var array<string, string|Rule|Closure|list<string|Rule|Closure>> */
    private array $rules;

    /** @var array<string, string> */
    private array $customMessages;

    private RuleRegistry $registry;

    /** @var array<string, list<string>> */
    private array $messages = [];

    /** @var list<string> */
    private array $passedFields = [];

    private bool $resolved = false;

    /** Modificadores: controlan el flujo, no son reglas del registro. */
    private const MODIFIERS = ['sometimes', 'nullable', 'bail'];

    public function __construct(array $data, array $rules, ?RuleRegistry $registry = null, array $customMessages = [])
    {
        $this->data = $data;
        $this->rules = $rules;
        $this->registry = $registry ?? new RuleRegistry();
        $this->customMessages = $customMessages;
    }

    /**
     * Crear un validador.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $customMessages
     */
    public static function make(
        array $data,
        array $rules,
        array $customMessages = [],
        ?RuleRegistry $registry = null
    ): self {
        return new self($data, $rules, $registry, $customMessages);
    }

    public function passes(): bool
    {
        $this->resolve();

        return $this->messages === [];
    }

    public function fails(): bool
    {
        return !$this->passes();
    }

    /**
     * Errores agrupados por campo: ['email' => ['El campo email ...']]
     *
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        $this->resolve();

        return $this->messages;
    }

    /**
     * Todos los mensajes en una sola lista.
     *
     * @return list<string>
     */
    public function errorsAll(): array
    {
        $all = [];

        foreach ($this->errors() as $messages) {
            foreach ($messages as $message) {
                $all[] = $message;
            }
        }

        return $all;
    }

    /**
     * Primer mensaje de error (de un campo o del primero que falle).
     */
    public function first(?string $field = null): ?string
    {
        $errors = $this->errors();

        if ($field !== null) {
            return $errors[$field][0] ?? null;
        }

        foreach ($errors as $messages) {
            if ($messages !== []) {
                return $messages[0];
            }
        }

        return null;
    }

    /**
     * Datos de los campos que pasaron la validación (útil para persistir).
     * Los campos omitidos (`sometimes` ausente, `nullable` vacío) no se incluyen.
     *
     * @return array<string, mixed>
     */
    public function validated(): array
    {
        $this->resolve();

        $validated = [];

        foreach ($this->passedFields as $field) {
            $validated[$field] = $this->data[$field] ?? null;
        }

        return $validated;
    }

    /**
     * Validar y lanzar ValidationException si hay errores.
     *
     * @throws ValidationException
     */
    public function validateOrFail(): void
    {
        if ($this->fails()) {
            throw new ValidationException($this->errors());
        }
    }

    /**
     * Registrar una regla personalizada en el registro de esta instancia.
     */
    public function extend(string $name, callable $rule, string $message): self
    {
        $this->registry->register($name, $rule, $message);

        return $this;
    }

    private function resolve(): void
    {
        if ($this->resolved) {
            return;
        }

        $this->messages = [];
        $this->passedFields = [];

        foreach ($this->rules as $field => $fieldRules) {
            $fieldRules = $this->normalizeRules($fieldRules);
            $present = array_key_exists($field, $this->data);
            $value = $this->data[$field] ?? null;

            $sometimes = in_array('sometimes', $fieldRules, true);
            $nullable = in_array('nullable', $fieldRules, true);
            $required = in_array('required', $fieldRules, true);
            $bail = in_array('bail', $fieldRules, true);

            // sometimes: campo ausente no se valida (ni entra a validated())
            if ($sometimes && !$present) {
                continue;
            }

            // nullable: vacío es válido (salvo que también exista required)
            if ($nullable && !$required && $this->isEmpty($value)) {
                continue;
            }

            $failed = false;

            foreach ($fieldRules as $rule) {
                if (in_array($rule, self::MODIFIERS, true)) {
                    continue;
                }

                if (!$this->rulePasses($rule, $field, $value)) {
                    $this->messages[$field][] = $this->messageFor($rule, $field, $value);
                    $failed = true;

                    // bail: detener la validación de este campo en el primer error
                    if ($bail) {
                        break;
                    }
                }
            }

            if (!$failed) {
                $this->passedFields[] = $field;
            }
        }

        $this->resolved = true;
    }

    /**
     * @param  string|Rule|Closure  $rule
     */
    private function rulePasses(string|Rule|Closure $rule, string $field, mixed $value): bool
    {
        if ($rule instanceof Rule) {
            return $rule->passes($field, $value, $this->data);
        }

        if ($rule instanceof Closure) {
            return (bool) $rule($value, $this->data);
        }

        [$name, $params] = $this->parseRule($rule);
        [$ruleFn] = $this->registry->get($name);

        return (bool) $ruleFn($value, $params, $this->data, $field);
    }

    /**
     * @param  string|Rule|Closure  $rule
     */
    private function messageFor(string|Rule|Closure $rule, string $field, mixed $value): string
    {
        if ($rule instanceof Rule) {
            $message = $this->customMessages[$field] ?? $rule->message($field, $value);
            $params = [];

            return $this->replacePlaceholders($message, $field, $params);
        }

        if ($rule instanceof Closure) {
            $message = $this->customMessages[$field . '.closure']
                ?? $this->customMessages[$field]
                ?? 'El campo :field no es válido.';
            $params = [];

            return $this->replacePlaceholders($message, $field, $params);
        }

        [$name, $params] = $this->parseRule($rule);
        [, $template] = $this->registry->get($name);

        $message = $this->customMessages[$field . '.' . $name]
            ?? $this->customMessages[$field]
            ?? $template;

        return $this->replacePlaceholders($message, $field, $params);
    }

    /**
     * Normalizar "a|b|c", ["a", "b|c"], un Rule o un Closure a una lista de reglas.
     *
     * @param  string|Rule|Closure|list<string|Rule|Closure>  $rules
     * @return list<string|Rule|Closure>
     */
    private function normalizeRules(string|Rule|Closure|array $rules): array
    {
        if (is_string($rules)) {
            return $rules === '' ? [] : explode('|', $rules);
        }

        if ($rules instanceof Rule || $rules instanceof Closure) {
            return [$rules];
        }

        $normalized = [];

        foreach ($rules as $rule) {
            if (is_string($rule) && str_contains($rule, '|')) {
                foreach (explode('|', $rule) as $part) {
                    $normalized[] = $part;
                }
            } else {
                $normalized[] = $rule;
            }
        }

        return $normalized;
    }

    /**
     * Separar "min:3" en ["min", ["3"]]. La regex conserva todo el patrón.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function parseRule(string $rule): array
    {
        if (str_contains($rule, ':')) {
            [$name, $rest] = explode(':', $rule, 2);
        } else {
            $name = $rule;
            $rest = '';
        }

        $params = $name === 'regex'
            ? [$rest]
            : ($rest === '' ? [] : explode(',', $rest));

        return [$name, $params];
    }

    private function replacePlaceholders(string $message, string $field, array $params): string
    {
        $fieldLabel = str_replace(['_', '-'], ' ', $field);

        $message = str_replace(':field', $fieldLabel, $message);
        $message = str_replace(':params', implode(', ', $params), $message);

        foreach ($params as $i => $param) {
            $message = str_replace(':param' . ($i + 1), (string) $param, $message);
        }

        $message = str_replace(':param', (string) ($params[0] ?? ''), $message);

        return $message;
    }

    private function isEmpty(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if ($value === '') {
            return true;
        }

        if (is_array($value)) {
            return count($value) === 0;
        }

        return false;
    }
}