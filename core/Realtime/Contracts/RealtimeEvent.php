<?php

namespace Apollo\Core\Realtime\Contracts;

interface RealtimeEvent
{
    public function name(): string;

    public function channel(): string;

    public function data(): array;
}