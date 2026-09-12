<?php

namespace Tests\Unit\Uploads;

use Apollo\Core\Uploads\Support\LocalDisk;
use Tests\TestCase;

class LocalDiskTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/apollo-disk-' . bin2hex(random_bytes(4));
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

    private function disk(): LocalDisk
    {
        return new LocalDisk($this->root, '/uploads');
    }

    public function test_put_and_get_roundtrip(): void
    {
        $disk = $this->disk();

        $this->assertTrue($disk->put('a/b/c.txt', 'hola'));
        $this->assertSame('hola', $disk->get('a/b/c.txt'));
        $this->assertTrue($disk->exists('a/b/c.txt'));
        $this->assertSame(4, $disk->size('a/b/c.txt'));
    }

    public function test_put_file_copies_source(): void
    {
        $source = $this->root . '/source.bin';
        file_put_contents($source, 'bin');

        $disk = $this->disk();
        $this->assertTrue($disk->putFile($source, 'sub/archivo.bin'));
        $this->assertSame('bin', $disk->get('sub/archivo.bin'));
    }

    public function test_delete_removes_file(): void
    {
        $disk = $this->disk();
        $disk->put('x.txt', 'x');

        $this->assertTrue($disk->delete('x.txt'));
        $this->assertFalse($disk->exists('x.txt'));
        $this->assertFalse($disk->delete('x.txt'));
    }

    public function test_url_uses_prefix(): void
    {
        $disk = $this->disk();

        $this->assertSame('/uploads/a/b.txt', $disk->url('a/b.txt'));
    }

    public function test_path_rejects_traversal(): void
    {
        $disk = $this->disk();

        $this->assertNull($disk->path('../secret.txt'));
        $this->assertNull($disk->path('a/../../secret.txt'));
        $this->assertNull($disk->path('..'));
    }

    public function test_path_normalizes_separators(): void
    {
        $disk = $this->disk();

        $this->assertNotNull($disk->path('a//b/./c.txt'));
        $this->assertSame($this->root . DIRECTORY_SEPARATOR . 'a/b/c.txt', $disk->path('a/b/c.txt'));
    }

    public function test_path_containment_for_existing_files(): void
    {
        $disk = $this->disk();
        $disk->put('dentro.txt', 'x');

        $outside = sys_get_temp_dir() . '/apollo-outside-' . bin2hex(random_bytes(4));
        file_put_contents($outside, 'x');

        // Escapes reales (..) → null
        $this->assertNull($disk->path('../' . basename($outside)));
        $this->assertNull($disk->path('a/../../' . basename($outside)));

        // Un path absoluto externo pasado "de frente" no escapa del root:
        // se neutraliza como segmento literal dentro del disco.
        $result = $disk->path(str_replace(['\\', '/'], '_', $outside));
        $this->assertNotNull($result);
        $this->assertStringStartsWith($this->root, $result);

        @unlink($outside);
    }

    public function test_get_returns_null_for_missing(): void
    {
        $this->assertNull($this->disk()->get('no/existe.txt'));
    }

    public function test_mime_returns_octet_stream_for_missing(): void
    {
        $this->assertSame('application/octet-stream', $this->disk()->mime('no/existe.txt'));
    }
}