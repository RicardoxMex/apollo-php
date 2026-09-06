<?php

namespace Apollo\Core\Realtime\Contracts;

interface EventBus
{
    public function publish(string $topic, array $payload): void;

    public function subscribe(string $topic, callable $handler): void;

    public function unsubscribe(string $topic): void;
}