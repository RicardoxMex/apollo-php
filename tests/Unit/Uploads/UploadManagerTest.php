<?php

namespace Tests\Unit\Uploads;

use Apollo\Core\Uploads\Exceptions\UploadException;
use Apollo\Core\Uploads\Support\UploadManager;
use Tests\TestCase;

class UploadManagerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/apollo-manager-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $file) {
            is_dir($file) ? $this->rrmdir($file) : @unlink($file);
        }
        @rmdir($dir);
    }

    private function manager(array $overrides = []): UploadManager
    {
        return new UploadManager(array_merge([
            'driver' => 'local',
            'root' => $this->root,
            'url_prefix' => '/uploads',
            'max_size' => 1024,
            'allowed_mimes' => ['png', 'pdf'],
            'overwrite' => false,
        ], $overrides));
    }

    private function fakeFile(string $name, string $content, string $mime = 'image/png'): array
    {
        $path = $this->root . '/tmp-' . bin2hex(random_bytes(4));
        file_put_contents($path, $content);

        return [
            'name' => $name,
            'type' => $mime,
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($content),
        ];
    }

    public function test_store_generates_unique_sanitized_name(): void
    {
        $result = $this->manager()->store($this->fakeFile('Mi Foto (1).PNG', 'x'));

        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}\.png$/', $result['name']);
        $this->assertStringNotContainsString('/', $result['path']);
        $this->assertStringEndsWith('/uploads/' . $result['name'], $result['url']);
        $this->assertSame(1, $result['size']);
        $this->assertSame('image/png', $result['mime']);
        $this->assertFileExists($this->root . '/' . $result['path']);
    }

    public function test_store_into_subdirectory(): void
    {
        $result = $this->manager()->store($this->fakeFile('a.png', 'x'), '2026/09');

        $this->assertSame('2026/09', dirname($result['path']));
        $this->assertFileExists($this->root . '/' . $result['path']);
    }

    public function test_store_accepts_uploaded_file_object(): void
    {
        $uploaded = \Apollo\Core\Uploads\Support\UploadedFile::fromArray($this->fakeFile('a.png', 'x'));

        $result = $this->manager()->store($uploaded);

        $this->assertStringEndsWith('.png', $result['name']);
    }

    public function test_store_as_uses_custom_name(): void
    {
        $result = $this->manager()->storeAs($this->fakeFile('a.png', 'x'), 'docs', 'informe.pdf');

        $this->assertSame('docs/informe.pdf', $result['path']);
    }

    public function test_store_as_avoids_collision_with_suffix(): void
    {
        $manager = $this->manager();
        $manager->storeAs($this->fakeFile('a.png', 'x'), '', 'mismo.png');

        $second = $manager->storeAs($this->fakeFile('b.png', 'y'), '', 'mismo.png');

        $this->assertNotSame('mismo.png', $second['name']);
        $this->assertSame('mismo_1.png', $second['name']);
    }

    public function test_store_as_overwrites_when_configured(): void
    {
        $manager = $this->manager(['overwrite' => true]);
        $manager->storeAs($this->fakeFile('a.png', 'x'), '', 'mismo.png');
        $second = $manager->storeAs($this->fakeFile('b.png', 'y'), '', 'mismo.png');

        $this->assertSame('mismo.png', $second['name']);
        $this->assertSame('y', $manager->get('mismo.png'));
    }

    public function test_store_rejects_oversized_file(): void
    {
        $manager = $this->manager(['max_size' => 1]);

        $this->expectException(UploadException::class);
        $this->expectExceptionMessage('supera el tamaño máximo');

        $manager->store($this->fakeFile('grande.png', str_repeat('a', 2048)));
    }

    public function test_store_rejects_disallowed_mime(): void
    {
        $manager = $this->manager();

        $this->expectException(UploadException::class);
        $this->expectExceptionMessage('no permitido');

        $manager->store($this->fakeFile('virus.exe', 'MZ'));
    }

    public function test_store_rejects_mime_sniff_mismatch(): void
    {
        // Extensión permitida pero contenido real de otro tipo
        $path = $this->root . '/tmp-' . bin2hex(random_bytes(4));
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));

        $file = [
            'name' => 'foto.png',
            'type' => 'image/png',
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($path),
        ];

        $manager = $this->manager(['allowed_mimes' => ['pdf']]);

        $this->expectException(UploadException::class);

        $manager->store($file);
    }

    public function test_store_rejects_invalid_upload(): void
    {
        $manager = $this->manager();

        $this->expectException(UploadException::class);

        $manager->store([
            'name' => 'a.png',
            'tmp_name' => $this->root . '/no-existe.png',
            'error' => UPLOAD_ERR_OK,
            'size' => 0,
        ]);
    }

    public function test_store_rejects_invalid_entry(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->manager()->store(['nada' => 'que ver']);
    }

    public function test_delete_and_exists(): void
    {
        $manager = $this->manager();
        $result = $manager->store($this->fakeFile('a.png', 'x'));

        $this->assertTrue($manager->exists($result['path']));
        $this->assertTrue($manager->delete($result['path']));
        $this->assertFalse($manager->exists($result['path']));
    }

    public function test_path_blocks_traversal(): void
    {
        $manager = $this->manager();

        $this->assertNull($manager->path('../outside.txt'));
        $this->assertFalse($manager->exists('../outside.txt'));
        $this->assertFalse($manager->delete('../outside.txt'));
    }

    public function test_unknown_driver_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Driver de uploads desconocido');

        $this->manager(['driver' => 's3'])->disk();
    }
}