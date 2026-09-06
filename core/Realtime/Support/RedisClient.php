<?php

namespace Apollo\Core\Realtime\Support;

/**
 * RedisConnection implementada sobre el protocolo RESP por streams TCP.
 * Suficiente para PUBLISH/SUBSCRIBE + PING (no requiere extensión Redis).
 */
class RedisClient implements \Apollo\Core\Realtime\Contracts\RedisConnection
{
    private array $config;
    private $socket = null;
    private ?string $error = null;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'host' => '127.0.0.1',
            'port' => 6379,
            'password' => null,
            'database' => 0,
            'timeout' => 1.0,
        ], $config);
    }

    public function connect(): void
    {
        if ($this->socket) {
            return;
        }

        $errno = 0;
        $errstr = '';

        $socket = @stream_socket_client(
            "tcp://{$this->config['host']}:{$this->config['port']}",
            $errno,
            $errstr,
            (float) $this->config['timeout']
        );

        if ($socket === false) {
            $this->error = "Redis connect failed: {$errstr}";
            throw new \RuntimeException($this->error);
        }

        stream_set_timeout($socket, (int) $this->config['timeout']);
        $this->socket = $socket;

        if (!empty($this->config['password'])) {
            $this->command('AUTH', $this->config['password']);
        }

        if ((int) $this->config['database'] > 0) {
            $this->command('SELECT', (string) $this->config['database']);
        }
    }

    public function disconnect(): void
    {
        if ($this->socket) {
            fclose($this->socket);
            $this->socket = null;
        }
    }

    public function connected(): bool
    {
        return $this->socket !== null;
    }

    public function ping(): bool
    {
        try {
            $this->connect();
            $response = $this->command('PING');
            return is_string($response) && strtoupper($response) === 'PONG';
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
            $this->disconnect();
            return false;
        }
    }

    public function publish(string $channel, string $message): int
    {
        $this->connect();
        return (int) $this->command('PUBLISH', $channel, $message);
    }

    /**
     * Bucle de suscripción bloqueante: entrega (channel, payload) al callback.
     */
    public function subscribeLoop(array $channels, callable $onMessage): void
    {
        $this->connect();
        $this->command('SUBSCRIBE', ...$channels);

        while ($this->socket && !feof($this->socket)) {
            $response = $this->readReply();

            if (!is_array($response) || count($response) < 3) {
                continue;
            }

            // RESP publish push: [message, channel, payload]
            if (strtolower((string) $response[0]) === 'message') {
                $channel = (string) $response[1];
                $payload = $response[2];
                $onMessage($channel, $payload);
            }
        }
    }

    /**
     * Bucle de suscripción por patrón (PSUBSCRIBE): entrega (channel, payload).
     * P. ej. patrón '*' recibe todos los canales.
     */
    public function psubscribeLoop(array $patterns, callable $onMessage): void
    {
        $this->connect();
        $this->command('PSUBSCRIBE', ...$patterns);

        while ($this->socket && !feof($this->socket)) {
            $response = $this->readReply();

            if (!is_array($response) || count($response) < 4) {
                continue;
            }

            // RESP push: [pmessage, pattern, channel, payload]
            if (strtolower((string) $response[0]) === 'pmessage') {
                $channel = (string) $response[2];
                $payload = $response[3];
                $onMessage($channel, $payload);
            }
        }
    }

    /**
     * Enviar un comando RESP y leer su respuesta.
     */
    public function command(string ...$parts)
    {
        $this->connect();

        $payload = '*' . count($parts) . "\r\n";
        foreach ($parts as $part) {
            $payload .= '$' . strlen($part) . "\r\n" . $part . "\r\n";
        }

        fwrite($this->socket, $payload);

        return $this->readReply();
    }

    private function readReply()
    {
        $line = fgets($this->socket);

        if ($line === false) {
            throw new \RuntimeException('Redis connection closed');
        }

        $line = trim($line);
        if ($line === '') {
            return null;
        }

        $type = $line[0];
        $data = substr($line, 1);

        return match ($type) {
            '+' => $data,
            '-' => throw new \RuntimeException("Redis error: {$data}"),
            ':' => (int) $data,
            '$' => $this->readBulk((int) $data),
            '*' => $this->readMulti((int) $data),
            default => throw new \RuntimeException("Redis unknown reply type: {$type}"),
        };
    }

    private function readBulk(int $length): ?string
    {
        if ($length < 0) {
            return null;
        }

        $data = '';
        while (strlen($data) < $length + 2) {
            $chunk = fread($this->socket, $length + 2 - strlen($data));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
        }

        return substr($data, 0, $length);
    }

    private function readMulti(int $count): array
    {
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = $this->readReply();
        }
        return $items;
    }

    public function getError(): ?string
    {
        return $this->error;
    }
}