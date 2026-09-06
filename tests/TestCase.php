<?php

namespace Tests;

use Apollo\Core\Application;
use Apollo\Core\Http\Request;
use Apollo\Core\Http\Response;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected static ?Application $app = null;

    public static function setUpBeforeClass(): void
    {
        // Entorno determinístico para tests (sin tocar .env)
        $_ENV['APP_DEBUG'] = false;
        $_ENV['JWT_SECRET_KEY'] = 'unit-test-secret-0123456789abcdef';
        $_ENV['JWT_ALGORITHM'] = 'HS256';

        self::$app = new Application(dirname(__DIR__));
        self::$app->make('config');
    }

    protected function app(): Application
    {
        return self::$app;
    }

    /**
     * Construir una Request in-process (sin servidor web)
     */
    protected function makeRequest(string $method, string $uri, array $headers = []): Request
    {
        $server = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $uri,
            'HTTP_HOST' => 'localhost',
        ];

        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return new Request([], [], [], [], [], $server, '');
    }

    /**
     * Despachar una petición a través de la aplicación completa (sin DB).
     *
     * @return array{0: int, 1: mixed} [status, body-decoded]
     */
    protected function dispatch(string $method, string $uri, array $headers = []): array
    {
        $response = self::$app->handle($this->makeRequest($method, $uri, $headers));

        if (!$response instanceof Response) {
            return [500, $response];
        }

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)];
    }
}