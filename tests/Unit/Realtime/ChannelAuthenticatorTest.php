<?php

namespace Tests\Unit\Realtime;

use Apollo\Core\Realtime\Auth\ChannelAuthenticator;
use Apollo\Core\Realtime\Support\RealtimeConfig;
use PHPUnit\Framework\TestCase;

class ChannelAuthenticatorTest extends TestCase
{
    private function authenticator(array $config = [], ?callable $authorize = null): ChannelAuthenticator
    {
        $full = array_merge([
            'app' => ['key' => 'app_apollo', 'secret' => 's3cret'],
            'auth' => ['authorize' => $authorize],
        ], $config);

        return new ChannelAuthenticator(new RealtimeConfig($full));
    }

    public function test_public_channels_are_always_authorized(): void
    {
        $auth = $this->authenticator();

        $this->assertTrue($auth->isPublic('orders'));
        $this->assertTrue($auth->authorize('orders', 1));
    }

    public function test_default_rule_requires_explicit_approval(): void
    {
        $auth = $this->authenticator();

        // Sin callback de app: los canales privados se rechazan por defecto
        // (seguridad: solo ticket firmado o decisión explícita de la app)
        $this->assertFalse($auth->authorize('private-user.25', 25));
        $this->assertFalse($auth->authorize('private-user.25', 99));
    }

    public function test_app_callback_decides_access(): void
    {
        $auth = $this->authenticator([], fn($channel, $user) => $user === 42);

        $this->assertTrue($auth->authorize('private-orders.1', 42));
        $this->assertFalse($auth->authorize('private-orders.1', 7));
    }

    public function test_ticket_roundtrip_and_verification(): void
    {
        $auth = $this->authenticator();

        $ticket = $auth->sign('private-user.25', 25);

        $this->assertTrue($auth->verifyTicket('private-user.25', 25, $ticket));
        $this->assertFalse($auth->verifyTicket('private-user.25', 99, $ticket));
        $this->assertFalse($auth->verifyTicket('private-user.25', 25, strrev($ticket)));
    }

    public function test_sign_requires_secret(): void
    {
        $auth = $this->authenticator(['app' => ['key' => 'k', 'secret' => '']]);

        $this->expectException(\RuntimeException::class);

        $auth->sign('private-user.1', 1);
    }
}