<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

use Spark\Foundation\Application;

final class ApplicationBindingsTest extends FrameworkTestCase
{
    public function test_application_dependency_uses_the_current_instance(): void
    {
        $this->assertSame($this->app, app(Application::class));
        $this->assertSame($this->app, $this->app->make(BindingTestConsumer::class)->app);
    }

    public function test_contracts_share_lazy_services_and_follow_replacements(): void
    {
        $this->app->setConfig('queue.default', 'file');
        $this->app->setConfig('queue.connections.file.path', $this->storagePath . '/queue');

        foreach ([
            Spark\Translator\Contracts\TranslatorContract::class => Spark\Translator\Translator::class,
            Spark\Database\Contracts\DBContract::class => Spark\Database\DB::class,
            Spark\View\Contracts\BladeContract::class => Spark\View\Blade::class,
            Spark\Queue\Contracts\QueueContract::class => Spark\Queue\Queue::class,
            Spark\Contracts\Http\RequestContract::class => Spark\Http\Request::class,
            Spark\Contracts\Http\ResponseContract::class => Spark\Http\Response::class,
            Spark\Contracts\Http\GateContract::class => Spark\Http\Gate::class,
        ] as $contract => $service) {
            $this->assertFalse($this->app->resolved($service));
            $this->assertSame($this->app->get($service), $this->app->get($contract));
        }

        $incoming = Spark\Http\Request::fromPsrRequest(new Nyholm\Psr7\ServerRequest('GET', '/current'));
        $this->app->instance(Spark\Http\Request::class, $incoming);
        $this->assertSame($incoming, $this->app->get(Spark\Contracts\Http\RequestContract::class));

        $translator = new Spark\Translator\Translator();
        $this->app->bind(Spark\Translator\Contracts\TranslatorContract::class, fn() => $translator);
        $this->assertSame($translator, $this->app->get(Spark\Translator\Contracts\TranslatorContract::class));
    }
}

final class BindingTestConsumer
{
    public function __construct(public Application $app)
    {
    }
}
