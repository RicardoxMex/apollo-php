<?php
// core/Console/Commands/MiddlewareTestCommand.php

namespace Apollo\Core\Console\Commands;

use Apollo\Core\Console\Command;
use Apollo\Core\Application;
use Apollo\Core\Http\Request;

class MiddlewareTestCommand extends Command
{
    protected string $signature = 'test:middleware';
    protected string $description = 'Run middleware smoke tests (no database required)';

    private Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function handle(): int
    {
        $this->line('🧪 Testing Middleware System');
        $this->line('============================');

        try {
            $failures = 0;

            $run = function (string $method, string $path, array $headers = []) {
                $server = [
                    'REQUEST_METHOD' => $method,
                    'REQUEST_URI' => $path,
                    'HTTP_HOST' => 'localhost',
                ];

                foreach ($headers as $name => $value) {
                    $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
                }

                $request = new Request([], [], [], [], [], $server, '');
                $response = $this->app->handle($request);

                return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)];
            };

            $check = function (string $name, int $expected, array $actual) use (&$failures) {
                $status = $actual[0];
                $ok = $status === $expected;

                if (!$ok) {
                    $failures++;
                }

                $this->line(($ok ? '✅' : '❌') . " {$name}: esperado {$expected}, obtenido {$status}");
            };

            [$status, $body] = $run('GET', '/api/users/test');
            $check('Ruta pública con LoggingMiddleware (200)', 200, [$status, $body]);

            [$status, $body] = $run('GET', '/api/users/profile');
            $check('Ruta protegida sin token (401)', 401, [$status, $body]);

            [$status, $body] = $run('GET', '/api/users/profile', ['Authorization' => 'Bearer invalid-token']);
            $check('Ruta protegida con token inválido (401)', 401, [$status, $body]);

            [$status, $body] = $run('GET', '/api/auth/admin/users');
            $check('Ruta admin sin token (401)', 401, [$status, $body]);

            [$status, $body] = $run('GET', '/api/no-existe');
            $check('Ruta inexistente (404)', 404, [$status, $body]);

            $this->line();

            if ($failures > 0) {
                $this->error("❌ {$failures} chequeo(s) fallaron");
                return 1;
            }

            $this->info('✅ Todos los chequeos pasaron');
            return 0;
        } catch (\Throwable $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            return 1;
        }
    }
}