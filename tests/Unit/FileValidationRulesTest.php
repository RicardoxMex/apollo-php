<?php

namespace Tests\Unit;

use Apollo\Core\Uploads\Support\UploadedFile;
use Apollo\Core\Validation\Validator;
use Tests\TestCase;

class FileValidationRulesTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/apollo-rules-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);

        parent::tearDown();
    }

    private function upload(string $name, string $content, int $error = UPLOAD_ERR_OK, ?string $mime = null): array
    {
        $path = $this->tmpDir . '/' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($path, $content);

        return [
            'name' => $name,
            'type' => $mime ?? 'application/octet-stream',
            'tmp_name' => $path,
            'error' => $error,
            'size' => strlen($content),
        ];
    }

    public function test_file_rule_accepts_valid_upload(): void
    {
        $validator = Validator::make(
            ['archivo' => $this->upload('a.png', 'x')],
            ['archivo' => 'file']
        );

        $this->assertTrue($validator->passes());
    }

    public function test_file_rule_accepts_uploaded_file_object(): void
    {
        $file = UploadedFile::fromArray($this->upload('a.png', 'x'));

        $validator = Validator::make(['archivo' => $file], ['archivo' => 'file']);

        $this->assertTrue($validator->passes());
    }

    public function test_file_rule_rejects_string(): void
    {
        $validator = Validator::make(['archivo' => 'hola.txt'], ['archivo' => 'file']);

        $this->assertFalse($validator->passes());
        $this->assertSame('El campo archivo debe ser un archivo válido.', $validator->first());
    }

    public function test_file_rule_rejects_failed_upload(): void
    {
        $validator = Validator::make(
            ['archivo' => $this->upload('a.png', 'x', UPLOAD_ERR_NO_FILE)],
            ['archivo' => 'file']
        );

        $this->assertFalse($validator->passes());
    }

    public function test_mimes_rule_by_extension(): void
    {
        $validator = Validator::make(
            ['foto' => $this->upload('Foto.JPG', 'x')],
            ['foto' => 'mimes:jpg,png']
        );

        $this->assertTrue($validator->passes());

        $bad = Validator::make(
            ['foto' => $this->upload('malo.exe', 'x')],
            ['foto' => 'mimes:jpg,png']
        );

        $this->assertFalse($bad->passes());
        $this->assertSame('El campo foto debe ser un archivo de tipo: jpg, png.', $bad->first());
    }

    public function test_mimes_rule_rejects_missing_name(): void
    {
        $validator = Validator::make(['foto' => 'no-array'], ['foto' => 'mimes:jpg']);

        $this->assertFalse($validator->passes());
    }

    public function test_image_rule(): void
    {
        $validator = Validator::make(
            ['foto' => $this->upload('a.png', 'x', UPLOAD_ERR_OK, 'image/png')],
            ['foto' => 'image']
        );

        $this->assertTrue($validator->passes());

        $pdf = Validator::make(
            ['foto' => $this->upload('a.pdf', 'x', UPLOAD_ERR_OK, 'application/pdf')],
            ['foto' => 'image']
        );

        $this->assertFalse($pdf->passes());
    }

    public function test_max_limits_file_size_in_kb(): void
    {
        $big = $this->upload('grande.png', str_repeat('a', 2048));

        $validator = Validator::make(['foto' => $big], ['foto' => 'max:1']);

        $this->assertFalse($validator->passes());
        $this->assertSame('El campo foto debe ser menor o igual a 1.', $validator->first());

        $ok = Validator::make(['foto' => $this->upload('peque.png', 'x')], ['foto' => 'max:1']);
        $this->assertTrue($ok->passes());
    }

    public function test_min_and_between_file_size_in_kb(): void
    {
        $file = $this->upload('medio.png', str_repeat('a', 2048));

        $min = Validator::make(['foto' => $file], ['foto' => 'min:2']);
        $this->assertTrue($min->passes());

        $between = Validator::make(['foto' => $file], ['foto' => 'between:1,3']);
        $this->assertTrue($between->passes());

        $below = Validator::make(['foto' => $file], ['foto' => 'between:3,5']);
        $this->assertFalse($below->passes());
    }

    public function test_size_rule_file_in_kb(): void
    {
        $file = $this->upload('exacto.png', str_repeat('a', 2048));

        $validator = Validator::make(['foto' => $file], ['foto' => 'size:2']);

        $this->assertTrue($validator->passes());
    }

    public function test_max_still_works_for_strings_and_arrays(): void
    {
        $validator = Validator::make(['txt' => 'abc'], ['txt' => 'max:3']);
        $this->assertTrue($validator->passes());

        $array = Validator::make(['items' => [1, 2, 3, 4]], ['items' => 'max:3']);
        $this->assertFalse($array->passes());

        $num = Validator::make(['n' => 5], ['n' => 'max:10']);
        $this->assertTrue($num->passes());
    }

    public function test_full_pipeline_combined(): void
    {
        $validator = Validator::make(
            ['foto' => $this->upload('foto.jpg', 'x', UPLOAD_ERR_OK, 'image/jpeg')],
            ['foto' => 'required|file|mimes:jpg,png|image|max:10240']
        );

        $this->assertTrue($validator->passes());
    }
}