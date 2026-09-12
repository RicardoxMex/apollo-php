<?php

namespace Tests\Unit\Uploads;

use Apollo\Core\Uploads\Support\UploadedFile;
use Tests\TestCase;

class UploadedFileTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/apollo-uploads-' . bin2hex(random_bytes(4));
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

    private function fakeFile(string $name, string $content, int $error = UPLOAD_ERR_OK): array
    {
        $path = $this->tmpDir . '/' . basename($name);
        file_put_contents($path, $content);

        return [
            'name' => $name,
            'type' => 'image/png',
            'tmp_name' => $path,
            'error' => $error,
            'size' => strlen($content),
        ];
    }

    public function test_from_array_wraps_upload(): void
    {
        $file = UploadedFile::fromArray($this->fakeFile('foto.png', 'png'));

        $this->assertInstanceOf(UploadedFile::class, $file);
        $this->assertSame('foto.png', $file->originalName());
        $this->assertSame('png', $file->extension());
        $this->assertSame(3, $file->size());
        $this->assertSame('image/png', $file->clientMime());
        $this->assertTrue($file->isValid());
    }

    public function test_from_array_returns_null_for_multi_file(): void
    {
        $this->assertNull(UploadedFile::fromArray([
            'name' => ['a.png', 'b.png'],
            'tmp_name' => ['/tmp/a', '/tmp/b'],
            'error' => [0, 0],
        ]));
    }

    public function test_from_array_returns_null_without_keys(): void
    {
        $this->assertNull(UploadedFile::fromArray(['foo' => 'bar']));
    }

    public function test_invalid_when_error_differs_from_ok(): void
    {
        $file = UploadedFile::fromArray($this->fakeFile('a.png', 'x', UPLOAD_ERR_NO_FILE));

        $this->assertFalse($file->isValid());
    }

    public function test_extension_is_lowercase_and_sanitized(): void
    {
        $file = UploadedFile::fromArray($this->fakeFile('Foto.PNG', 'x'));

        $this->assertSame('png', $file->extension());

        $evil = UploadedFile::fromArray($this->fakeFile('mal.php.jpeg', 'x'));
        $this->assertSame('jpeg', $evil->extension());

        $noExt = UploadedFile::fromArray($this->fakeFile('sin-ext', 'x'));
        $this->assertSame('', $noExt->extension());
    }

    public function test_mime_sniffs_real_content_when_available(): void
    {
        $path = $this->tmpDir . '/real.png';
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));

        $file = UploadedFile::fromArray([
            'name' => 'real.png',
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($path),
        ]);

        if (function_exists('finfo_open')) {
            $this->assertSame('image/png', $file->mime());
        } else {
            // Sin extensión fileinfo: fallback al MIME declarado por el cliente
            $this->assertSame('application/octet-stream', $file->mime());
        }
    }

    public function test_hash_name_is_hex(): void
    {
        $file = UploadedFile::fromArray($this->fakeFile('a.png', 'x'));

        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $file->hashName());
    }

    public function test_store_and_store_as_delegate_to_manager(): void
    {
        $root = $this->tmpDir . '/store';
        $manager = new \Apollo\Core\Uploads\Support\UploadManager([
            'driver' => 'local',
            'root' => $root,
            'max_size' => 1024,
            'allowed_mimes' => ['png'],
        ]);
        \Apollo\Core\Container\Container::getInstance()->instance(
            \Apollo\Core\Uploads\Support\UploadManager::class,
            $manager
        );

        $file = UploadedFile::fromArray($this->fakeFile('logo.png', 'data'));

        $result = $file->store('img');
        $this->assertSame('img', dirname($result['path']));
        $this->assertStringEndsWith('.png', $result['name']);
        $this->assertFileExists($root . '/' . $result['path']);

        $named = $file->storeAs('docs', 'informe.txt');
        $this->assertSame('docs/informe.txt', $named['path']);
    }
}