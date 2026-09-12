<?php

namespace Apollo\Core\Realtime\Support;

/**
 * Acceso tipado a la config del módulo realtime.
 */
class RealtimeConfig
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function driver(): string
    {
        return strtolower($this->config['driver'] ?? 'auto');
    }

    public function host(): string
    {
        return $this->config['host'] ?? '0.0.0.0';
    }

    public function port(): int
    {
        return (int) ($this->config['port'] ?? 8080);
    }

    public function maxConnections(): int
    {
        return (int) ($this->config['max_connections'] ?? 10000);
    }

    public function maxMessageSize(): int
    {
        return (int) ($this->config['max_message_size'] ?? 8192);
    }

    public function heartbeatInterval(): int
    {
        return (int) ($this->config['heartbeat_interval'] ?? 30);
    }

    public function connectionTimeout(): int
    {
        return (int) ($this->config['connection_timeout'] ?? 60);
    }

    public function appKey(): string
    {
        return $this->config['app']['key'] ?? 'app_apollo';
    }

    public function appSecret(): string
    {
        return $this->config['app']['secret'] ?? '';
    }

    public function redisConfig(): array
    {
        return $this->config['redis'] ?? [];
    }

    public function notificationsDatabase(): bool
    {
        return (bool) ($this->config['notifications']['database'] ?? true);
    }

    public function authorizeCallback(): ?callable
    {
        return $this->config['auth']['authorize'] ?? null;
    }

    public function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? true);
    }

    public function publicUrl(): ?string
    {
        $url = $this->config['public_url'] ?? null;
        return ($url === '' || $url === null) ? null : (string) $url;
    }

    public function pollInterval(): int
    {
        $interval = (int) ($this->config['poll_interval'] ?? 1);
        return $interval < 1 ? 1 : $interval;
    }

    public function sslEnabled(): bool
    {
        return (bool) ($this->config['ssl']['enabled'] ?? false);
    }

    public function sslLocalCert(): ?string
    {
        $path = $this->config['ssl']['local_cert'] ?? null;
        return ($path === '' || $path === null) ? null : (string) $path;
    }

    public function sslLocalKey(): ?string
    {
        $path = $this->config['ssl']['local_key'] ?? null;
        return ($path === '' || $path === null) ? null : (string) $path;
    }

    public function all(): array
    {
        return $this->config;
    }
}