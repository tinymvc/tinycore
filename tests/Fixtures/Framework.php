<?php

class CoreFixtureCors extends \Spark\Foundation\Http\Middlewares\CorsAccessControl
{
    public function __construct()
    {
        parent::__construct(['origin' => ['https://app.example.com'], 'methods' => ['POST', 'OPTIONS'], 'headers' => ['Content-Type']]);
    }
}

class CoreFixtureResource extends \Spark\Http\Resources\JsonResource
{
    public function toArray(?\Spark\Http\Request $request = null): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'secret' => $this->when($request?->header('x-show-secret') === 'yes', fn () => $this->secret),
            'child' => $this->whenLoaded('child', fn ($child) => self::make($child)),
            'posts_count' => $this->whenCounted('posts'),
        ];
    }
}

class CoreFixtureResourceModel extends \Spark\Database\Model
{
    protected string $table = 'resource_models';
    public function child(): never
    {
        throw new \LogicException('Resource triggered lazy loading.');
    }
}

class CoreFixtureIdentity extends \Spark\Database\Model
{
}

class CoreFixtureCorsForm extends \Spark\Foundation\Http\FormRequest
{
    public function rules(): array
    {
        return ['name' => 'required'];
    }
}

class CoreFixtureTokenUser extends \Spark\Database\Model
{
    protected string $table = 'docs_token_users';
    protected const USE_TIMESTAMPS = false;
}

function core_queue_output_failure(): void
{
    throw new \RuntimeException("First line\r\n\033[31mSecond line\033[0m\033]0;hidden title\007");
}

class CoreFixtureStorageProbe extends \Spark\Testing\ApplicationTestCase
{
    public string $allocatedPath;

    public function __construct(private string $root, private string $mode)
    {
    }

    protected function testStorageDirectory(): string
    {
        return $this->root;
    }

    protected function createApplication(): \Spark\Foundation\Application
    {
        $this->allocatedPath = $this->storagePath;
        mkdir($this->storagePath . '/nested');
        file_put_contents($this->storagePath . '/nested/data', 'test');
        symlink($this->root . '/keep', $this->storagePath . '/external-link');

        if ($this->mode === 'boot') {
            throw new \RuntimeException('Injected boot failure.');
        }

        $app = new class($this->storagePath) extends \Spark\Foundation\Application {
            public bool $failFlush = false;

            public function flush(): void
            {
                parent::flush();

                if ($this->failFlush) {
                    throw new \RuntimeException('Injected shutdown failure.');
                }
            }
        };
        $app->failFlush = $this->mode === 'flush';

        return $app;
    }

    public function test_storage(): void
    {
        $this->assertTrue(is_file($this->storagePath . '/nested/data'));

        if ($this->mode === 'failure') {
            throw new \RuntimeException('Injected test failure.');
        }
    }
}

