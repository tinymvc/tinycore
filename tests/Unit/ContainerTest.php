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

class StaticCallbackFixture
{
    private function __construct() {}

    public static function value(DependencyFixture $dependency): string
    {
        return 'static';
    }
}

final class ContainerTest extends TestCase
{
    public function test_invokable_objects_and_static_callable_strings_receive_dependencies(): void
    {
        $container = new Container;
        $callback = new class {
            public function __invoke(DependencyFixture $dependency, string $label): string
            {
                return $label;
            }

            public static function label(DependencyFixture $dependency, string $label): string
            {
                return $label;
            }
        };
        $this->assertSame('object', $container->call($callback, ['label' => 'object']));
        $this->assertSame('static', $container->call($callback::class . '::label', ['label' => 'static']));
        $container->bind('factory', $callback);
        $this->assertSame('factory', $container->make('factory', ['label' => 'factory']));
    }

    public function test_static_array_callable_does_not_construct_its_class(): void
    {
        $container = new Container;
        $this->assertSame('static', $container->call([StaticCallbackFixture::class, 'value']));
    }

    public function test_explicit_typed_argument_keeps_its_identity_and_positional_alignment(): void
    {
        $container = new Container;
        $dependency = new DependencyFixture;
        $this->assertSame([$dependency, 'value'], $container->call(
            fn (DependencyFixture $provided, string $label) => [$provided, $label],
            [$dependency, 'value'],
        ));
    }

    public function test_variadic_arguments_expand_and_allow_an_empty_list(): void
    {
        $container = new Container;
        $callback = fn (DependencyFixture $dependency, string ...$values) => $values;
        $this->assertSame([], $container->call($callback));
        $this->assertSame(['a', 'b'], $container->call($callback, ['a', 'b']));
        $this->assertSame(['a', 'b'], $container->call($callback, ['values' => ['a', 'b']]));
    }

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
