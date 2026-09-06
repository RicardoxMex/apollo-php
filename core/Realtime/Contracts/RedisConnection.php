<?php

namespace Apollo\Core\Realtime\Contracts;

/**
 * Cliente Redis mínimo (RESP sobre streams) para Pub/Sub y health check.
 * No reemplaza una extensión/paquete Redis: es el mínimo para el bus.
 */
interface RedisConnection
{
    public function connect(): void;

    public function disconnect(): void;

    public function connected(): bool;

    public function ping(): bool;

    public function publish(string $channel, string $message): int;

    /**
     * Bucle de suscripción bloqueante: entrega (channel, payload) al callback.
     */
    public function subscribeLoop(array $channels, callable $onMessage): void;
}