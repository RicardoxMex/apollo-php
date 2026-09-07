# Validación de Datos (core/Validation)

## Descripción General

El núcleo de Apollo incluye un motor de validación de datos (`core/Validation`, namespace `Apollo\Core\Validation`) inspirado en la validación de Laravel/DRF: reglas declarativas, mensajes de error por campo y soporte para reglas personalizadas. Es parte del **core** — disponible en cualquier app sin configuración extra.

## Uso Básico

```php
use Apollo\Core\Validation\Validator;

$validator = Validator::make($data, [
    'titulo'  => 'required|string|min:3|max:120',
    'email'   => 'required|email',
    'monto'   => 'numeric|min:0',
    'estado'  => 'in:draft,open,finished',
]);

if ($validator->fails()) {
    // Errores agrupados por campo: ['email' => ['El campo email ...']]
    $errors = $validator->errors();
}
```

### API principal

| Método | Qué hace |
|---|---|
| `Validator::make($data, $rules, $messages = [])` | Crea el validador (factory estática) |
| `passes(): bool` | `true` si todos los campos son válidos |
| `fails(): bool` | Negación de `passes()` |
| `errors(): array` | Errores agrupados por campo → `['email' => ['msg1', 'msg2']]` |
| `errorsAll(): array` | Todos los mensajes en una sola lista |
| `first(?string $field = null): ?string` | Primer mensaje de error (de un campo o del primero encontrado) |
| `validated(): array` | Solo los campos que pasaron la validación (listo para persistir) |
| `validateOrFail(): void` | Lanza `ValidationException` (con `errors()`) si hay errores |
| `extend($name, $rule, $message)` | Registra una regla personalizada en la instancia |

### Helper global

```php
$v = validator($data, $rules);   // igual que Validator::make(...)
```

## Sintaxis de Reglas

Tres formas equivalentes:

```php
// 1. Cadena con pipes
'titulo' => 'required|string|min:3|max:120'

// 2. Array
'titulo' => ['required', 'string', 'min:3', 'max:120']

// 3. Objeto / closure
'codigo' => new CodigoUnicoRule()
'slug'   => fn ($value) => strlen($value) > 2
```

Las reglas con parámetros usan `:`, y los parámetros se separan por coma: `min:3`, `between:18,65`, `in:pending,accepted,rejected`.

> **Nota:** la regex con `|` o `,` debe ir en sintaxis de array (el pipe separa reglas, la coma separa parámetros).

### Modificadores

| Modificador | Efecto |
|---|---|
| `sometimes` | Solo valida si el campo está presente en los datos |
| `nullable` | Valor vacío (`null`, `''`, `[]`) es válido |
| `bail` | Detiene la validación del campo en el primer error |

## Reglas Disponibles

| Regla | Ejemplo | Descripción |
|---|---|---|
| `required` | `required` | No vacío (acepta `0` y `'0'`) |
| `email` | `email` | Email válido |
| `string` | `string` | Es una cadena |
| `numeric` | `numeric` | Valor numérico |
| `integer` | `integer` | Número entero |
| `boolean` | `boolean` | `true/false/1/0/'1'/'0'/...` |
| `array` | `array` | Es un array |
| `json` | `json` | JSON válido |
| `url` | `url` | URL válida |
| `ip` | `ip` | Dirección IP válida |
| `alpha` | `alpha` | Solo letras |
| `alpha_num` | `alpha_num` | Solo letras y números |
| `date` | `date` | Fecha parseable |
| `date_format:f` | `date_format:Y-m-d` | Fecha con formato exacto (sin rollover) |
| `min:n` | `min:3` | String: longitud · número: valor · array: count |
| `max:n` | `max:120` | Igual que `min` pero por arriba |
| `between:a,b` | `between:18,65` | Dentro del rango |
| `size:n` | `size:8` | Exactamente `n` |
| `in:a,b,c` | `in:draft,open` | Debe estar en la lista |
| `not_in:a,b,c` | `not_in:admin,root` | No debe estar en la lista |
| `same:campo` | `same:password` | Igual a otro campo |
| `different:campo` | `different:email` | Diferente de otro campo |
| `confirmed` | `confirmed` | Igual a `<campo>_confirmation` |
| `regex:patrón` | `regex:/^[A-Z]{3}$/` | Cumple el patrón |
| `unique:tabla,columna[,idIgnorado]` | `unique:usuarios,email,42` | Valor único en la tabla (requiere BD) |
| `exists:tabla,columna` | `exists:roles,id` | El valor existe (requiere BD) |

## Mensajes Personalizados

```php
$v = Validator::make($data, [
    'titulo' => 'required|string|min:3',
], [
    'titulo.required' => 'Falta el título del torneo.',
    'titulo.min'      => 'El título es demasiado corto (mínimo :param).',
]);
```

- Clave `'campo.regla'` → mensaje para esa regla concreta.
- Clave `'campo'` → mensaje de respaldo para cualquier regla de ese campo.
- Placeholders: `:field` (nombre del campo), `:param` / `:param1` / `:param2` / `:params` (parámetros de la regla).

## Reglas Personalizadas

### Closure

```php
$v = Validator::make($data, [
    'nombre' => ['required', fn ($value, $data) => strlen($value) <= 50],
]);
```

### Objeto `Rule`

```php
use Apollo\Core\Validation\Rule;

class CodigoUnicoRule implements Rule
{
    public function passes(string $field, mixed $value, array $data): bool
    {
        return !empty($value) && preg_match('/^[A-Z]{3}-\d+$/', $value) === 1;
    }

    public function message(string $field, mixed $value): string
    {
        return "El campo {$field} debe ser tipo ABC-123.";
    }
}
```

### `extend()` (por instancia)

```php
$v = Validator::make($data, ['v' => 'mi_regla'])
    ->extend('mi_regla', fn ($value) => ... , 'Mensaje personalizado.');
```

## Reglas con Base de Datos

`unique` y `exists` consultan la conexión configurada (`DB_CONNECTION`, MySQL o SQLite):

```php
'titulo'    => 'unique:torneos,titulo',            // nuevo: no debe existir
'email'     => 'unique:usuarios,email,42',         // edición: ignora el id 42
'role_id'   => 'exists:roles,id',                  // FK: debe existir
```

La tabla y columna las define el desarrollador (nunca el cliente), por lo que son seguras aunque se interpolan en el SQL. Si no hay conexión configurada, lanzan un error claro.

## Integración con Controllers

El `Controller` base expone `validate()`, que valida, lanza `ValidationException` y devuelve solo los datos validados:

```php
use Apollo\Core\Validation\ValidationException;

class TorneoController extends Controller
{
    public function store(Request $request)
    {
        try {
            $data = $this->validate($request->all(), [
                'titulo'    => 'required|string|min:3|max:120',
                'deporte'   => 'required|string',
                'formato'   => 'required|in:eliminacion-directa,grupos,liga',
                'fechaInicio' => 'required|date',
                'costoInscripcion' => 'numeric|min:0',
                'organizadorId' => 'required|exists:usuarios,id',
            ]);

            // $data solo contiene campos válidos → crear torneo
        } catch (ValidationException $e) {
            return $this->json([
                'error' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        }
    }
}
```

## Testing

El módulo tiene cobertura propia:

```bash
php -d extension=pdo_sqlite vendor/bin/phpunit --filter Validation
# 37 tests: reglas puras (sin DB) + unique/exists sobre SQLite :memory:
```