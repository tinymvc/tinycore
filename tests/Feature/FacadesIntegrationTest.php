<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

use Spark\Facades\App;
use Spark\Facades\Auth;
use Spark\Facades\Blade;
use Spark\Facades\Cache;
use Spark\Facades\DB;
use Spark\Facades\Event;
use Spark\Facades\Facade;
use Spark\Facades\Gate;
use Spark\Facades\Hash;
use Spark\Facades\Http;
use Spark\Facades\Lock;
use Spark\Facades\Log;
use Spark\Facades\Mail;
use Spark\Facades\Queue;
use Spark\Facades\Route;
use Spark\Facades\Schema;
use Spark\Facades\Session;
use Spark\Facades\Storage;
use Spark\Facades\Validator;

final class FacadesIntegrationTest extends FrameworkTestCase
{
    public function test_facade_base_and_app(): void
    {
        $this->assertTrue(App::isTesting());
        $this->assertIsString(App::getPath());
        $this->assertTrue(App::bound('db'));
        $this->assertTrue(App::isAlias('cache'));
    }

    public function test_blade_facade(): void
    {
        Blade::directive('customDirective', function () {
            return '<?php echo "custom"; ?>';
        });

        Blade::clearCache();
        $this->assertFalse(Blade::exists('nonexistent_template_xyz'));
    }

    public function test_cache_facade_fundamental_methods(): void
    {
        Cache::set('test_key', 'test_value');
        $this->assertTrue(Cache::has('test_key'));
        $this->assertSame('test_value', Cache::get('test_key'));
        $this->assertSame('default', Cache::get('missing_key', 'default'));

        Cache::put('put_key', 'put_value');
        $this->assertSame('put_value', Cache::get('put_key'));

        Cache::forget('test_key');
        $this->assertFalse(Cache::has('test_key'));

        Cache::delete('put_key');
        $this->assertFalse(Cache::has('put_key'));

        $named = Cache::make('default');
        $this->assertInstanceOf(\Spark\Cache\Cache::class, $named);
    }

    public function test_db_facade(): void
    {
        $db = DB::connection();
        $this->assertInstanceOf(\Spark\Database\DB::class, $db);

        $result = DB::run('SELECT 42 as answer');
        $this->assertSame(42, (int) $result[0]['answer']);

        $table = DB::table('users');
        $this->assertInstanceOf(\Spark\Database\QueryBuilder::class, $table);
    }

    public function test_event_facade(): void
    {
        $heard = false;
        Event::listen('user.registered', function ($payload) use (&$heard) {
            $heard = $payload;
        });

        $this->assertTrue(Event::hasListeners('user.registered'));
        Event::dispatch('user.registered', 'john');
        $this->assertSame('john', $heard);

        Event::clearListeners('user.registered');
        $this->assertFalse(Event::hasListeners('user.registered'));
    }

    public function test_gate_facade(): void
    {
        Gate::define('edit-post', fn() => true);
        Gate::define('delete-post', fn() => false);

        $this->assertTrue(Gate::allows('edit-post'));
        $this->assertTrue(Gate::check('edit-post'));
        $this->assertTrue(Gate::denies('delete-post'));
        $this->assertFalse(Gate::check('delete-post'));
    }

    public function test_hash_facade(): void
    {
        $hashed = Hash::make('secret');
        $this->assertTrue(Hash::check('secret', $hashed));
        $this->assertFalse(Hash::check('wrong', $hashed));

        $copy = Hash::copy();
        $this->assertInstanceOf(\Spark\Hash::class, $copy);
    }

    public function test_http_facade(): void
    {
        $req = Http::withHeaders(['X-Test' => '123'])
            ->withToken('bearer-token')
            ->withTimeout(30)
            ->withUserAgent('TinyTest/1.0');

        $this->assertTrue(in_array('Authorization: Bearer bearer-token', $req->getHeaders(), true));
        $this->assertTrue(in_array('X-Test: 123', $req->getHeaders(), true));
    }

    public function test_lock_facade(): void
    {
        $lock = Lock::make('default');
        $this->assertInstanceOf(\Spark\Cache\Cache::class, $lock);
    }

    public function test_log_facade(): void
    {
        Log::log('info', 'Test log message', ['user_id' => 1]);
        Log::info('Info message');
        Log::error('Error message');
        $this->assertTrue(true);
    }

    public function test_mail_facade(): void
    {
        $mail = Mail::make()
            ->to('user@example.com', 'User')
            ->subject('Hello')
            ->withHeader('X-Mailer', 'Spark')
            ->body('World', false);

        $this->assertInstanceOf(\Spark\Utils\Mail::class, $mail);
    }

    public function test_route_facade(): void
    {
        Route::get('/facade-test-route', fn() => 'ok')->name('facade.test');
        $this->assertTrue(Route::has('facade.test'));
        $this->assertIsArray(Route::getRoutes());
    }

    public function test_storage_facade(): void
    {
        $storage = Storage::build(['driver' => 'local', 'root' => $this->storagePath . '/explicit']);
        $this->assertInstanceOf(\Spark\Storage\Storage::class, $storage);
        $this->assertInstanceOf(\Spark\Storage\Contracts\UploaderDriverInterface::class, $storage->getDriver());
    }

    public function test_queue_facade(): void
    {
        $this->assertTrue(is_subclass_of(Queue::class, Facade::class));
    }

    public function test_session_facade(): void
    {
        Session::put('facade_session_key', 'session_value');
        $this->assertTrue(Session::has('facade_session_key'));
        $this->assertSame('session_value', Session::get('facade_session_key'));
        Session::forget('facade_session_key');
        $this->assertFalse(Session::has('facade_session_key'));
    }

    public function test_schema_facade(): void
    {
        $connection = Schema::getConnection();
        $this->assertInstanceOf(\PDO::class, $connection);
        $grammar = Schema::getGrammar();
        $this->assertInstanceOf(\Spark\Database\Schema\Grammar::class, $grammar);
    }

    public function test_validator_facade(): void
    {
        $validator = Validator::make(['name' => 'required'], ['name' => 'John']);
        $this->assertTrue($validator->passes());
        $this->assertFalse($validator->fails());
        $this->assertSame('John', $validator->validated()->name);
    }

    public function test_auth_facade(): void
    {
        $this->assertTrue(Auth::isGuest());
        $this->assertFalse(Auth::check());
    }
}
