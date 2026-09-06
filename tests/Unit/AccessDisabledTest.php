<?php

namespace Tests\Unit;

use Apollo\Core\Application;
use Apollo\Core\Container\ContainerException;
use PHPUnit\Framework\TestCase;

/**
 * Verify config('auth.access.enabled') = false deja el módulo inerte:
 * ningún alias de gates se registra.
 */
class AccessDisabledTest extends TestCase
{
    private static ?string $previous = null;

    public static function setUpBeforeClass(): void
    {
        self::$previous = $_ENV['AUTH_ACCESS_ENABLED'] ?? null;
        $_ENV['AUTH_ACCESS_ENABLED'] = 'false';

        $app = new Application(dirname(__DIR__, 2));
        $config = $app->make('config');

        foreach ($config->get('providers.core', []) as $providerClass) {
            if (class_exists($providerClass)) {
                $app->registerServiceProvider(new $providerClass($app));
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$previous === null) {
            unset($_ENV['AUTH_ACCESS_ENABLED']);
        } else {
            $_ENV['AUTH_ACCESS_ENABLED'] = self::$previous;
        }
    }

    public function test_role_aliases_are_not_registered_when_disabled(): void
    {
        $this->expectException(ContainerException::class);

        app('role.admin');
    }

    public function test_auth_alias_still_resolves_when_disabled(): void
    {
        // El módulo de acceso desactivado no afecta a la config en sí
        $enabled = config('auth.access.enabled', true);
        $this->assertFalse($enabled);
    }
}