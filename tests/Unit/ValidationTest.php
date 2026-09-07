<?php

namespace Tests\Unit;

use Apollo\Core\Validation\Rule;
use Apollo\Core\Validation\ValidationException;
use Apollo\Core\Validation\Validator;
use InvalidArgumentException;
use Tests\TestCase;

class ValidationTest extends TestCase
{
    public function test_required(): void
    {
        $this->assertTrue(Validator::make(['name' => 'Apollo'], ['name' => 'required'])->passes());
        $this->assertFalse(Validator::make(['name' => ''], ['name' => 'required'])->passes());
        $this->assertFalse(Validator::make([], ['name' => 'required'])->passes());
        $this->assertFalse(Validator::make(['name' => null], ['name' => 'required'])->passes());
        $this->assertFalse(Validator::make(['name' => []], ['name' => 'required'])->passes());
    }

    public function test_required_accepts_zero(): void
    {
        $this->assertTrue(Validator::make(['n' => 0], ['n' => 'required'])->passes());
        $this->assertTrue(Validator::make(['n' => '0'], ['n' => 'required'])->passes());
    }

    public function test_email_rule(): void
    {
        $this->assertTrue(Validator::make(['email' => 'a@b.co'], ['email' => 'email'])->passes());
        $this->assertFalse(Validator::make(['email' => 'no-es-email'], ['email' => 'email'])->passes());
        $this->assertFalse(Validator::make(['email' => 123], ['email' => 'email'])->passes());
    }

    public function test_type_rules(): void
    {
        $this->assertTrue(Validator::make(['v' => 'texto'], ['v' => 'string'])->passes());
        $this->assertFalse(Validator::make(['v' => 123], ['v' => 'string'])->passes());

        $this->assertTrue(Validator::make(['v' => '12.5'], ['v' => 'numeric'])->passes());
        $this->assertFalse(Validator::make(['v' => 'abc'], ['v' => 'numeric'])->passes());

        $this->assertTrue(Validator::make(['v' => '42'], ['v' => 'integer'])->passes());
        $this->assertTrue(Validator::make(['v' => 42], ['v' => 'integer'])->passes());
        $this->assertFalse(Validator::make(['v' => 4.2], ['v' => 'integer'])->passes());
        $this->assertFalse(Validator::make(['v' => '4.2'], ['v' => 'integer'])->passes());

        $this->assertTrue(Validator::make(['v' => true], ['v' => 'boolean'])->passes());
        $this->assertTrue(Validator::make(['v' => 'false'], ['v' => 'boolean'])->passes());
        $this->assertFalse(Validator::make(['v' => 'talvez'], ['v' => 'boolean'])->passes());

        $this->assertTrue(Validator::make(['v' => ['a']], ['v' => 'array'])->passes());
        $this->assertFalse(Validator::make(['v' => 'a'], ['v' => 'array'])->passes());

        $this->assertTrue(Validator::make(['v' => '{"a":1}'], ['v' => 'json'])->passes());
        $this->assertTrue(Validator::make(['v' => 'null'], ['v' => 'json'])->passes());
        $this->assertFalse(Validator::make(['v' => '{no json'], ['v' => 'json'])->passes());
        $this->assertFalse(Validator::make(['v' => ['a']], ['v' => 'json'])->passes());
    }

    public function test_url_and_ip_rules(): void
    {
        $this->assertTrue(Validator::make(['v' => 'https://apollo.dev/ruta'], ['v' => 'url'])->passes());
        $this->assertFalse(Validator::make(['v' => 'no-url'], ['v' => 'url'])->passes());

        $this->assertTrue(Validator::make(['v' => '192.168.0.1'], ['v' => 'ip'])->passes());
        $this->assertFalse(Validator::make(['v' => '999.1.1.1'], ['v' => 'ip'])->passes());
    }

    public function test_alpha_rules(): void
    {
        $this->assertTrue(Validator::make(['v' => 'Hola'], ['v' => 'alpha'])->passes());
        $this->assertFalse(Validator::make(['v' => 'Hola123'], ['v' => 'alpha'])->passes());

        $this->assertTrue(Validator::make(['v' => 'Hola123'], ['v' => 'alpha_num'])->passes());
        $this->assertFalse(Validator::make(['v' => 'Hola 123'], ['v' => 'alpha_num'])->passes());
    }

    public function test_size_rules_on_strings(): void
    {
        $this->assertTrue(Validator::make(['v' => 'abc'], ['v' => 'min:3'])->passes());
        $this->assertFalse(Validator::make(['v' => 'ab'], ['v' => 'min:3'])->passes());

        $this->assertTrue(Validator::make(['v' => 'abc'], ['v' => 'max:3'])->passes());
        $this->assertFalse(Validator::make(['v' => 'abcd'], ['v' => 'max:3'])->passes());

        $this->assertTrue(Validator::make(['v' => 'abc'], ['v' => 'between:2,4'])->passes());
        $this->assertFalse(Validator::make(['v' => 'abcd'], ['v' => 'between:2,3'])->passes());

        $this->assertTrue(Validator::make(['v' => 'abc'], ['v' => 'size:3'])->passes());
        $this->assertFalse(Validator::make(['v' => 'ab'], ['v' => 'size:3'])->passes());
    }

    public function test_size_rules_on_numbers(): void
    {
        $this->assertTrue(Validator::make(['v' => 18], ['v' => 'min:18'])->passes());
        $this->assertFalse(Validator::make(['v' => 17], ['v' => 'min:18'])->passes());

        $this->assertTrue(Validator::make(['v' => 65], ['v' => 'max:65'])->passes());
        $this->assertFalse(Validator::make(['v' => 66], ['v' => 'max:65'])->passes());

        $this->assertTrue(Validator::make(['v' => 30], ['v' => 'between:18,65'])->passes());
        $this->assertFalse(Validator::make(['v' => 12], ['v' => 'between:18,65'])->passes());
    }

    public function test_size_rules_on_arrays(): void
    {
        $this->assertTrue(Validator::make(['v' => ['a', 'b']], ['v' => 'min:2'])->passes());
        $this->assertFalse(Validator::make(['v' => ['a']], ['v' => 'min:2'])->passes());

        $this->assertTrue(Validator::make(['v' => ['a', 'b']], ['v' => 'size:2'])->passes());
        $this->assertFalse(Validator::make(['v' => ['a', 'b', 'c']], ['v' => 'size:2'])->passes());
    }

    public function test_in_and_not_in_rules(): void
    {
        $this->assertTrue(Validator::make(['estado' => 'open'], ['estado' => 'in:draft,open,finished'])->passes());
        $this->assertFalse(Validator::make(['estado' => 'live'], ['estado' => 'in:draft,open,finished'])->passes());

        $this->assertTrue(Validator::make(['v' => 2], ['v' => 'in:1,2,3'])->passes());
        $this->assertFalse(Validator::make(['v' => ['a']], ['v' => 'in:1,2,3'])->passes());

        $this->assertFalse(Validator::make(['v' => 'admin'], ['v' => 'not_in:admin,root'])->passes());
        $this->assertTrue(Validator::make(['v' => 'user'], ['v' => 'not_in:admin,root'])->passes());
    }

    public function test_date_rules(): void
    {
        $this->assertTrue(Validator::make(['v' => '2026-09-06'], ['v' => 'date'])->passes());
        $this->assertFalse(Validator::make(['v' => 'no-es-fecha'], ['v' => 'date'])->passes());

        $this->assertTrue(Validator::make(['v' => '2026-09-06'], ['v' => 'date_format:Y-m-d'])->passes());
        $this->assertFalse(Validator::make(['v' => '06/09/2026'], ['v' => 'date_format:Y-m-d'])->passes());
        // Round-trip: marzo no tiene 45 días
        $this->assertFalse(Validator::make(['v' => '2026-03-45'], ['v' => 'date_format:Y-m-d'])->passes());
    }

    public function test_comparison_rules(): void
    {
        $data = ['password' => 'secreto', 'password_confirmation' => 'secreto'];
        $this->assertTrue(Validator::make($data, ['password' => 'confirmed'])->passes());

        $data = ['password' => 'secreto', 'password_confirmation' => 'otra'];
        $this->assertFalse(Validator::make($data, ['password' => 'confirmed'])->passes());

        $this->assertTrue(Validator::make(['a' => 'x', 'b' => 'x'], ['a' => 'same:b'])->passes());
        $this->assertFalse(Validator::make(['a' => 'x', 'b' => 'y'], ['a' => 'same:b'])->passes());

        $this->assertTrue(Validator::make(['a' => 'x', 'b' => 'y'], ['a' => 'different:b'])->passes());
        $this->assertFalse(Validator::make(['a' => 'x', 'b' => 'x'], ['a' => 'different:b'])->passes());
    }

    public function test_regex_rule(): void
    {
        $this->assertTrue(Validator::make(['v' => 'ABC'], ['v' => 'regex:/^[A-Z]{3}$/'])->passes());
        $this->assertFalse(Validator::make(['v' => 'abc'], ['v' => 'regex:/^[A-Z]{3}$/'])->passes());
    }

    public function test_pipe_and_array_syntax_are_equivalent(): void
    {
        $rules = ['required', 'email', 'min:6'];

        $this->assertTrue(Validator::make(['email' => 'a@b.co'], ['email' => 'required|email|min:6'])->passes());
        $this->assertTrue(Validator::make(['email' => 'a@b.co'], ['email' => $rules])->passes());
        $this->assertFalse(Validator::make(['email' => 'a@b.co'], ['email' => 'required|email|min:20'])->passes());
    }

    public function test_errors_are_grouped_by_field(): void
    {
        $v = Validator::make(
            ['email' => 'mal', 'age' => 12],
            ['email' => 'required|email', 'age' => 'min:18']
        );

        $this->assertTrue($v->fails());
        $this->assertFalse($v->passes());
        $this->assertSame(
            ['El campo email debe ser un email válido.', 'El campo age debe ser mayor o igual a 18.'],
            $v->errorsAll()
        );
        $this->assertArrayHasKey('email', $v->errors());
        $this->assertArrayHasKey('age', $v->errors());
        $this->assertSame('El campo email debe ser un email válido.', $v->first('email'));
        $this->assertSame('El campo email debe ser un email válido.', $v->first());
    }

    public function test_nullable_rule(): void
    {
        $rules = ['nota' => 'nullable|string|max:10'];

        $this->assertTrue(Validator::make(['nota' => ''], $rules)->passes());
        $this->assertTrue(Validator::make(['nota' => 'hola'], $rules)->passes());
        $this->assertFalse(Validator::make(['nota' => 123], $rules)->passes());
    }

    public function test_nullable_does_not_override_required(): void
    {
        $this->assertFalse(Validator::make(['v' => ''], ['v' => 'required|nullable'])->passes());
    }

    public function test_sometimes_rule(): void
    {
        $rules = ['slug' => 'sometimes|string|min:3'];

        $this->assertTrue(Validator::make([], $rules)->passes());
        $this->assertTrue(Validator::make(['slug' => 'abc'], $rules)->passes());
        $this->assertFalse(Validator::make(['slug' => 'ab'], $rules)->passes());
    }

    public function test_sometimes_absent_field_is_not_in_validated(): void
    {
        $v = Validator::make([], ['slug' => 'sometimes|string']);

        $this->assertTrue($v->passes());
        $this->assertSame([], $v->validated());
    }

    public function test_bail_stops_at_first_error(): void
    {
        $v = Validator::make(
            ['email' => 'mal'],
            ['email' => 'bail|required|email|min:100']
        );

        $this->assertFalse($v->passes());
        // min:100 nunca se evalúa; solo aparece el error de email
        $this->assertSame(['El campo email debe ser un email válido.'], $v->errors()['email']);
    }

    public function test_validated_returns_only_passing_fields(): void
    {
        $v = Validator::make(
            ['titulo' => 'Final de copa', 'premio' => ''],
            ['titulo' => 'required|string', 'premio' => 'required']
        );

        $this->assertTrue($v->fails());
        $this->assertSame(['titulo' => 'Final de copa'], $v->validated());
    }

    public function test_custom_messages(): void
    {
        $v = Validator::make(
            ['titulo' => ''],
            ['titulo' => 'required'],
            ['titulo.required' => 'Falta el título del torneo.']
        );

        $this->assertSame(['Falta el título del torneo.'], $v->errors()['titulo']);
    }

    public function test_custom_message_fallback_for_the_whole_field(): void
    {
        $v = Validator::make(
            ['email' => 'mal'],
            ['email' => 'email'],
            ['email' => 'El email no es válido.']
        );

        $this->assertSame(['El email no es válido.'], $v->errors()['email']);
    }

    public function test_closure_rule(): void
    {
        $v = Validator::make(
            ['nombre' => 'ab'],
            ['nombre' => fn ($value) => strlen($value) >= 3]
        );

        $this->assertTrue($v->fails());
        $this->assertSame(['El campo nombre no es válido.'], $v->errors()['nombre']);
    }

    public function test_rule_object(): void
    {
        $empiezaConX = new class implements Rule {
            public function passes(string $field, mixed $value, array $data): bool
            {
                return str_starts_with((string) $value, 'x');
            }

            public function message(string $field, mixed $value): string
            {
                return "El campo {$field} debe empezar con x.";
            }
        };

        $v = Validator::make(['codigo' => 'abc'], ['codigo' => $empiezaConX]);

        $this->assertTrue($v->fails());
        $this->assertSame(['El campo codigo debe empezar con x.'], $v->errors()['codigo']);
        $this->assertTrue(Validator::make(['codigo' => 'xyz'], ['codigo' => $empiezaConX])->passes());
    }

    public function test_rule_object_receives_full_data(): void
    {
        $matcheaCon = new class implements Rule {
            public function passes(string $field, mixed $value, array $data): bool
            {
                return (string) $value === (string) ($data['prefijo'] ?? '') . '-x';
            }

            public function message(string $field, mixed $value): string
            {
                return 'No matchea.';
            }
        };

        $this->assertTrue(Validator::make(
            ['prefijo' => 'A', 'v' => 'A-x'],
            ['v' => $matcheaCon]
        )->passes());
    }

    public function test_extend_registers_custom_rule(): void
    {
        $v = Validator::make(['v' => 'no'], ['v' => 'empieza_con'])
            ->extend('empieza_con', fn ($value) => str_starts_with((string) $value, 'ok'), 'Debe empezar con ok.');

        $this->assertTrue($v->fails());
        $this->assertSame(['Debe empezar con ok.'], $v->errors()['v']);

        $this->assertTrue(
            Validator::make(['v' => 'ok si'], ['v' => 'empieza_con'])
                ->extend('empieza_con', fn ($value) => str_starts_with((string) $value, 'ok'), 'Debe empezar con ok.')
                ->passes()
        );
    }

    public function test_param_placeholders_in_messages(): void
    {
        $v = Validator::make(['edad' => 12], ['edad' => 'between:18,65']);

        $this->assertSame(['El campo edad debe estar entre 18 y 65.'], $v->errors()['edad']);

        $v = Validator::make(['estado' => 'live'], ['estado' => 'in:draft,finished']);
        $this->assertSame(['El campo estado debe ser uno de: draft, finished.'], $v->errors()['estado']);
    }

    public function test_validate_or_fail_throws(): void
    {
        $v = Validator::make(['email' => 'mal'], ['email' => 'email']);

        try {
            $v->validateOrFail();
            $this->fail('validateOrFail debería lanzar ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('email', $e->errors());
            $this->assertSame(['El campo email debe ser un email válido.'], $e->errorsAll());
        }
    }

    public function test_validate_or_fail_does_not_throw_when_valid(): void
    {
        $v = Validator::make(['email' => 'a@b.co'], ['email' => 'email']);

        $v->validateOrFail();

        $this->assertTrue(true);
    }

    public function test_unknown_rule_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Regla de validación desconocida: inexistente');

        Validator::make(['v' => 1], ['v' => 'inexistente'])->passes();
    }
}