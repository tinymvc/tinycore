<?php

namespace Tests\Unit;

use Spark\Container;
use Spark\Testing\TestCase;

class DependencyFixture
{
}

class ConsumerFixture
{
    public function __construct(public DependencyFixture $dependency, public string $label = 'default')
    {
    }
}

class CircularFixture
{
    public function __construct(public CircularFixture $dependency)
    {
    }
}

final class ContainerTest extends TestCase
{
    public function test_autowiring_singletons_aliases_and_forgetting(): void
    {
        $container = new Container;
        $container->singleton(DependencyFixture::class);
        $container->alias('dependency', DependencyFixture::class);
        $consumer = $container->get(ConsumerFixture::class);
        $this->assertSame('default', $consumer->label);
        $this->assertSame($consumer->dependency, $container->get('dependency'));
        $container->forgetInstance(DependencyFixture::class);
        $this->assertNotSame($consumer->dependency, $container->get(DependencyFixture::class));
        $container->bind('transient', fn () => new \stdClass);
        $this->assertNotSame($container->get('transient'), $container->get('transient'));
        $this->assertSame('injected', $container->call(fn (DependencyFixture $dependency, string $name) => $name, ['name' => 'injected']));
    }

    public function test_circular_resolution_does_not_poison_later_resolutions(): void
    {
        $container = new Container;
        $this->assertThrows(\Spark\Exceptions\Container\BuildServiceException::class, fn () => $container->get(CircularFixture::class));
        $this->assertTrue($container->get(DependencyFixture::class) instanceof DependencyFixture);
    }
}
