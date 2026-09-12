<?php

namespace Tests\Unit;

use Apollo\Core\Http\Response;
use Tests\TestCase;

class DownloadResponseTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/apollo-dl-' . bin2hex(random_bytes(4));
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

    public function test_download_sets_attachment_headers_and_body(): void
    {
        $path = $this->tmpDir . '/informe.txt';
        file_put_contents($path, 'contenido');

        $response = Response::download($path, 'informe.txt');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('contenido', $response->getContent());
        $this->assertSame('attachment; filename="informe.txt"', $response->getHeaders()['Content-Disposition']);
        $this->assertSame('9', $response->getHeaders()['Content-Length']);
        $this->assertArrayHasKey('Content-Type', $response->getHeaders());
    }

    public function test_download_defaults_name_to_basename(): void
    {
        $path = $this->tmpDir . '/archivo.bin';
        file_put_contents($path, 'x');

        $response = Response::download($path);

        $this->assertSame('attachment; filename="archivo.bin"', $response->getHeaders()['Content-Disposition']);
    }

    public function test_download_returns_404_for_missing_file(): void
    {
        $response = Response::download($this->tmpDir . '/no-existe.txt');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('Not Found', (string) $response->getContent());
    }

    public function test_download_strips_header_injection_from_name(): void
    {
        $path = $this->tmpDir . '/x.txt';
        file_put_contents($path, 'x');

        $response = Response::download($path, "mal\r\nInjected: 1");

        $this->assertStringNotContainsString("\r\n", $response->getHeaders()['Content-Disposition']);
    }

    public function test_file_serves_inline(): void
    {
        $path = $this->tmpDir . '/vista.png';
        file_put_contents($path, 'png');

        $response = Response::file($path);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('inline; filename="vista.png"', $response->getHeaders()['Content-Disposition']);
        $this->assertSame('png', $response->getContent());
    }

    public function test_file_returns_404_for_missing(): void
    {
        $this->assertSame(404, Response::file($this->tmpDir . '/no.png')->getStatusCode());
    }
}