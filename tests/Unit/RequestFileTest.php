<?php

namespace Tests\Unit;

use Apollo\Core\Http\Request;
use Apollo\Core\Uploads\Support\UploadedFile;
use Tests\TestCase;

class RequestFileTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/apollo-req-' . bin2hex(random_bytes(4));
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

    private function makeUpload(string $name, string $content, int $error = UPLOAD_ERR_OK): array
    {
        $path = $this->tmpDir . '/' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($path, $content);

        return [
            'name' => $name,
            'type' => 'image/png',
            'tmp_name' => $path,
            'error' => $error,
            'size' => strlen($content),
        ];
    }

    private function request(array $files): Request
    {
        return new Request([], [], [], [], $files, [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/uploads',
        ], '');
    }

    public function test_file_returns_uploaded_file_for_single(): void
    {
        $request = $this->request(['foto' => $this->makeUpload('a.png', 'x')]);

        $file = $request->file('foto');

        $this->assertInstanceOf(UploadedFile::class, $file);
        $this->assertSame('a.png', $file->originalName());
        $this->assertTrue($file->isValid());
    }

    public function test_file_returns_null_when_missing(): void
    {
        $request = $this->request([]);

        $this->assertNull($request->file('nada'));
    }

    public function test_file_returns_first_when_multi_single_element(): void
    {
        $request = $this->request(['files' => [
            'name' => ['a.png'],
            'type' => ['image/png'],
            'tmp_name' => [$this->tmpDir . '/a.png'],
            'error' => [UPLOAD_ERR_OK],
            'size' => [1],
        ]]);
        file_put_contents($this->tmpDir . '/a.png', 'x');

        $file = $request->file('files');

        $this->assertInstanceOf(UploadedFile::class, $file);
        $this->assertSame('a.png', $file->originalName());
    }

    public function test_has_file_is_true_only_for_valid_upload(): void
    {
        $request = $this->request([
            'ok' => $this->makeUpload('a.png', 'x'),
            'malo' => $this->makeUpload('b.png', 'x', UPLOAD_ERR_NO_FILE),
        ]);

        $this->assertTrue($request->hasFile('ok'));
        $this->assertFalse($request->hasFile('malo'));
        $this->assertFalse($request->hasFile('ausente'));
    }

    public function test_files_returns_list_for_multi_field(): void
    {
        file_put_contents($this->tmpDir . '/a.png', 'x');
        file_put_contents($this->tmpDir . '/b.png', 'y');

        $request = $this->request(['galeria' => [
            'name' => ['a.png', 'b.png'],
            'type' => ['image/png', 'image/png'],
            'tmp_name' => [$this->tmpDir . '/a.png', $this->tmpDir . '/b.png'],
            'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
            'size' => [1, 1],
        ]]);

        $files = $request->files('galeria');

        $this->assertCount(2, $files);
        $this->assertInstanceOf(UploadedFile::class, $files[0]);
        $this->assertSame(['a.png', 'b.png'], array_map(
            fn ($f) => $f->originalName(),
            $files
        ));
    }

    public function test_files_handles_indexed_multi_structure(): void
    {
        file_put_contents($this->tmpDir . '/a.png', 'x');
        file_put_contents($this->tmpDir . '/b.png', 'y');

        // files[0] y files[1] (nombres escalares indexados, mismo shape que files[])
        $request = $this->request(['files' => [
            'name' => [0 => 'a.png', 1 => 'b.png'],
            'type' => [0 => 'image/png', 1 => 'image/png'],
            'tmp_name' => [0 => $this->tmpDir . '/a.png', 1 => $this->tmpDir . '/b.png'],
            'error' => [0 => UPLOAD_ERR_OK, 1 => UPLOAD_ERR_OK],
            'size' => [0 => 1, 1 => 1],
        ]]);

        $files = $request->files('files');

        $this->assertCount(2, $files);
        $this->assertSame(['a.png', 'b.png'], array_map(
            fn ($f) => $f->originalName(),
            $files
        ));
    }

    public function test_files_returns_single_wrapped(): void
    {
        $request = $this->request(['foto' => $this->makeUpload('a.png', 'x')]);

        $files = $request->files('foto');

        $this->assertCount(1, $files);
        $this->assertInstanceOf(UploadedFile::class, $files[0]);
    }

    public function test_files_returns_empty_for_missing_field(): void
    {
        $request = $this->request([]);

        $this->assertSame([], $request->files('nada'));
    }

    public function test_all_files_returns_everything(): void
    {
        file_put_contents($this->tmpDir . '/a.png', 'x');
        file_put_contents($this->tmpDir . '/b.png', 'y');

        $request = $this->request([
            'foto' => $this->makeUpload('a.png', 'x'),
            'galeria' => [
                'name' => ['b.png'],
                'type' => ['image/png'],
                'tmp_name' => [$this->tmpDir . '/b.png'],
                'error' => [UPLOAD_ERR_OK],
                'size' => [1],
            ],
        ]);

        $files = $request->allFiles();

        $this->assertCount(2, $files);
        $this->assertSame(['a.png', 'b.png'], array_map(
            fn ($f) => $f->originalName(),
            $files
        ));
    }

    public function test_existing_api_unchanged(): void
    {
        $request = new Request(['q' => '1'], ['campo' => 'v'], [], [], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/ruta',
        ], '');

        $this->assertSame('1', $request->query('q'));
        $this->assertSame('v', $request->input('campo'));
        $this->assertSame('v', $request->get('campo'));
        $this->assertSame(['q' => '1', 'campo' => 'v'], $request->all());
    }
}