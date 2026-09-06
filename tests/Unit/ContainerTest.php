<?php

namespace Tests\Unit;

use Apollo\Core\Container\Container;
use Apollo\Core\Container\ContainerException;
use PHPUnit\Framework\TestCase;

class ContainerTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = Container::getInstance();
        // Abstract únicos por test para no colisionar entre tests
    }

    public function test_bind_and_make(): void
    {
        $this->container->bind('test.bind', fn() => new \stdClass());

        $instance = $this->container->make('test.bind');

        $this->assertInstanceOf(\stdClass::class, $instance);
        $this->assertNotSame($instance, $this->container->make('test.bind'), 'bind() no debe compartir instancias');
    }

    public function test_singleton_returns_same_instance(): void
    {
        $this->container->singleton('test.singleton', fn() => new \stdClass());

        $this->assertSame(
            $this->container->make('test.singleton'),
            $this->container->make('test.singleton')
        );
    }

    public function test_instance_binding(): void
    {
        $object = new \stdClass();
        $this->container->instance('test.instance', $object);

        $this->assertSame($object, $this->container->make('test.instance'));
    }

    public function test_alias_resolves_to_original(): void
    {
        $this->container->bind('test.alias.x', fn() => new \stdClass());
        $this->container->alias('test.alias.x', 'test.alias.x2');

        $this->assertInstanceOf(\stdClass::class, $this->container->make('test.alias.x2'));
    }

    public function test_make_unknown_class_throws(): void
    {
        $this->expectException(ContainerException::class);

        $this->container->make('Tests\\Unit\\ClassThatDoesNotExist_xyz');
    }

    public function test_app_helpers_resolve_through_container(): void
    {
        // El container plano no tiene binding 'config': enlazarlo para probar los helpers
        $this->container->singleton('config', fn() => new \Apollo\Core\Config());

        $this->assertNotNull(\app());
        $this->assertEmpty(\config('does.not.exist', []));
    }
}