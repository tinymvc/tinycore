<?php

require_once dirname(__DIR__) . '/Fixtures/Framework.php';

abstract class FrameworkTestCase extends \Spark\Testing\ApplicationTestCase
{
    protected function createApplication(): \Spark\Foundation\Application
    {
        return \Spark\Foundation\Application::create(path: $this->storagePath, config: [
            'app' => [
                'debug' => true,
                'key' => 'core-test-only-key-at-least-32-characters',
                'url' => 'http://localhost',
                'lang' => 'en',
                'temp_dir' => $this->storagePath . '/temp',
                'storage_dir' => $this->storagePath,
                'views_dir' => dirname(__DIR__, 2) . '/src/Foundation/resources/views',
            ],
            'database' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'cache' => ['driver' => 'file', 'connections' => ['file' => ['path' => $this->storagePath . '/cache']]],
        ], providers: []);
    }

    protected function tokenUser(): CoreFixtureTokenUser
    {
        \Spark\Database\Schema\Schema::create('docs_token_users', function ($table) {
            $table->id();
            $table->string('email');
            $table->string('username');
            $table->string('password');
        });
        \Spark\Database\Schema\Schema::create('docs_tokens', function ($table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expire_at');
            $table->timestamp('created_at');
        });
        return CoreFixtureTokenUser::create(['email' => 'ada@example.test', 'username' => 'ada', 'password' => bcrypt('secret')]);
    }

    protected function tokenAuth(bool $persisted = false): \Spark\Http\Auth
    {
        return new \Spark\Http\Auth(CoreFixtureTokenUser::class, [
            'channels' => ['jwt'],
            'jwt_token_table' => $persisted ? 'docs_tokens' : null,
        ]);
    }

    protected function bearer(?string $token): void
    {
        request()->headers->put('authorization', $token ? 'Bearer ' . $token : '');
    }
}
