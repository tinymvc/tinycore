<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

use Spark\Facades\Route;
use Spark\Http\Routing\Router;
use Spark\Http\Routing\Exceptions\InvalidNamedRouteException;

final class RoutingTest extends FrameworkTestCase
{
    public function test_optional_parameter_and_trailing_slash(): void
    {
        Route::get('/items/{id?}', fn($id = 'all') => ['id' => $id]);
        $this->get('/items')->assertExactJson(['id' => null]);
        $this->get('/items/7/')->assertExactJson(['id' => '7']);
    }

    public function test_static_route_characters_are_literal(): void
    {
        Route::get('/file.json', fn() => 'literal');
        $this->get('/file.json')->assertContent('literal');
        $this->get('/fileXjson')->assertNotFound();
    }

    public function test_match_selects_only_registered_methods(): void
    {
        Route::match(['PUT', 'PATCH'], '/items', fn() => 'updated');
        $this->put('/items')->assertContent('updated');
        $this->patch('/items')->assertContent('updated');
        $this->get('/items')->assertNotFound();
    }

    public function test_nested_groups_compose_paths_and_names_without_leaking(): void
    {
        Route::group(['prefix' => 'api'], function () {
            Route::group(['prefix' => 'v1'], fn() => Route::get('/items/{id}', fn($id) => $id)->name('show'));
        });
        Route::get('/health', fn() => 'ok')->name('health');
        $router = app(Router::class);
        $this->assertSame('/api/v1/items/7', $router->route('api.v1.show', ['id' => 7]));
        $this->assertSame('/health', $router->route('health'));
        $this->get('/api/v1/items/7')->assertContent('7');
        $this->get('/health')->assertContent('ok');
    }

    public function test_named_route_substitution_and_optional_removal(): void
    {
        Route::get('/items/{id}/{tab?}', fn() => 'ok')->name('item');
        $router = app(Router::class);
        $this->assertSame('/items/7', $router->route('item', ['id' => 7]));
        $this->assertSame('/items/7/edit', $router->route('item', ['id' => 7, 'tab' => 'edit']));
        $this->assertSame('/items/$1', $router->route('item', '$1'));
        $this->assertThrows(InvalidNamedRouteException::class, fn() => $router->route('missing'));
    }

    public function test_resource_only_and_except_filter_registered_actions(): void
    {
        Route::resource('/items', RoutingResourceFixture::class, only: ['index', 'show', 'destroy'], except: ['destroy']);
        $router = app(Router::class);
        $this->assertTrue($router->has('items.index'));
        $this->assertTrue($router->has('items.show'));
        $this->assertFalse($router->has('items.destroy'));
        $this->get('/items')->assertContent('index');
        $this->get('/items/8')->assertContent('show 8');
        $this->delete('/items/8')->assertNotFound();
    }

    public function test_redirect_preserves_status_and_location(): void
    {
        Route::redirect('/old', '/new', 301);
        $this->get('/old')->assertStatus(301)->assertHeader('Location', '/new');
    }

    public function test_fallback_receives_current_request(): void
    {
        Route::fallback(fn(\Spark\Http\Request $request) => new \Spark\Http\Response($request->getPath(), 404));
        $this->get('/unknown')->assertNotFound()->assertContent('/unknown');
    }
}

class RoutingResourceFixture
{
    public function index(): string
    {
        return 'index';
    }
    public function show(string $id): string
    {
        return 'show ' . $id;
    }
}
