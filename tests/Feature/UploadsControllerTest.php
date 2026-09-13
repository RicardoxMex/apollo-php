<?php

namespace Tests\Feature;

use Apollo\Core\Http\Request;
use Apollo\Core\Uploads\Support\UploadManager;
use Apps\Tournaments\Controllers\UploadController;
use Tests\TestCase;

class UploadsControllerTest extends TestCase
{
    private string $tmpDir;
    private UploadManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/apollo-upload-ctrl-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0775, true);

        $this->manager = new UploadManager([
            'driver' => 'local',
            'root' => $this->tmpDir,
            'url_prefix' => '/uploads',
            'max_size' => 10240,
            'allowed_mimes' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            'overwrite' => false,
        ]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->tmpDir);
        }

        parent::tearDown();
    }

    private function makeUpload(string $name, string $content): array
    {
        $path = $this->tmpDir . '/' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($path, $content);

        return [
            'name' => $name,
            'type' => 'image/png',
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($content),
        ];
    }

    /** PNG 1x1 real: finfo lo detecta como image/png (allowed_mimes). */
    private function pngReal(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
        );
    }

    private function request(array $files = []): Request
    {
        return new Request([], [], [], [], $files, [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/uploads',
        ], '');
    }

    private function controller(Request $request): UploadController
    {
        $container = $this->app();
        $container->instance('request', $request);
        $container->instance(Request::class, $request);

        return new UploadController($container, $this->manager);
    }

    public function test_store_guarda_con_upload_manager_y_devuelve_url(): void
    {
        $response = $this->controller($this->request(['file' => $this->makeUpload('escudo.png', $this->pngReal())]))->store();

        $this->assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        $this->assertTrue($body['success']);
        $this->assertStringStartsWith('/uploads/teams/', $body['data']['url']);
        $this->assertStringEndsWith('.png', $body['data']['path']);
        $this->assertSame($this->pngReal(), $this->manager->get($body['data']['path']));
    }

    public function test_store_rechaza_sin_archivo(): void
    {
        $response = $this->controller($this->request())->store();

        $this->assertSame(422, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame('Validación', $body['error']);
    }

    public function test_store_rechaza_tipo_no_permitido(): void
    {
        $response = $this->controller($this->request(['file' => $this->makeUpload('notas.txt', 'texto')]))->store();

        $this->assertSame(422, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame('Validación', $body['error']);
    }

    public function test_show_sirve_archivo_existente(): void
    {
        $stored = $this->manager->store($this->makeUpload('escudo.png', $this->pngReal()), 'teams');

        $response = $this->controller($this->request())->show($stored['path']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($this->pngReal(), (string) $response->getContent());
    }

    public function test_show_devuelve_404_para_archivo_inexistente(): void
    {
        $response = $this->controller($this->request())->show('teams/no-existe.png');

        $this->assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame('Archivo no encontrado', $body['error']);
    }

    public function test_show_rechaza_path_traversal(): void
    {
        $response = $this->controller($this->request())->show('../.env');

        $this->assertSame(404, $response->getStatusCode());
    }
}