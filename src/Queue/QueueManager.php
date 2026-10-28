<?php

namespace Spark\Queue;

use Spark\Foundation\Application;
use Spark\Queue\Contracts\JobContract;
use function array_key_exists;
use function in_array;
use function is_array;

/**
 * Resolves queue connections independently of queue names such as emails or reports.
 * 
 * @method void push(JobContract $job, string $queue = 'default')
 * @method void pushOnce(JobContract $job, string $queue = 'default')
 * @method void work(bool $once = false, int $timeout = 3600, int $sleep = 3, int $delay = 5, int $tries = 3, array|string $queue = 'default')
 */
class QueueManager
{
    /** @var array<string, Queue> */
    private array $connections = [];

    private ?\Closure $initializer = null;

    public function __construct(private readonly Application $app)
    {
    }

    public function connection(?string $name = null): Queue
    {
        $name ??= $this->getDefaultConnection();
        if (!isset($this->connections[$name])) {
            $queue = new Queue(
                self::resolveConfig((array) $this->app->getConfig('queue', []), $name),
                $name
            );
            $this->connections[$name] = $queue;

            try {
                if ($name === $this->getDefaultConnection() && $this->initializer !== null) {
                    ($this->initializer)($queue);
                }
            } catch (\Throwable $error) {
                if (($this->connections[$name] ?? null) === $queue) {
                    unset($this->connections[$name]);
                }
                throw $error;
            }
            return $queue;
        }
        return $this->connections[$name];
    }

    public function getDefaultConnection(): string
    {
        $config = (array) $this->app->getConfig('queue', []);
        return (string) (($config['default'] ?? null) ?: ($config['driver'] ?? null) ?: 'database');
    }

    public function purge(?string $name = null): void
    {
        unset($this->connections[$name ?? $this->getDefaultConnection()]);
    }

    /** @internal Retain lazy application withQueue() registration. */
    public function configure(?array $jobs = null, ?callable $then = null): void
    {
        $this->purge();
        $this->initializer = static function (Queue $queue) use ($jobs, $then): void {
            foreach ($jobs ?? [] as $job) {
                $queue->pushOnce($job);
            }
            $then && $then($queue);
        };
    }

    /** Signal all workers without opening a queue transport. */
    public function restart(): void
    {
        $this->app->get(\Spark\Cache\CacheManager::class)->make('queue:workers')
            ->put('restart', bin2hex(random_bytes(16)));
    }

    public function __call(string $method, array $arguments): mixed
    {
        return $this->connection()->$method(...$arguments);
    }

    /** @internal Resolve configuration without constructing an unrelated default connection. */
    public static function resolveConfig(array $config, ?string $name = null): array
    {
        $default = (string) (($config['default'] ?? null) ?: ($config['driver'] ?? null) ?: 'database');
        $name ??= $default;
        $connections = $config['connections'] ?? [];
        if (!is_array($connections)) {
            throw new \InvalidArgumentException('Queue connections must be an array.');
        }

        $connection = $connections[$name] ?? null;
        if (!is_array($connection)) {
            if (
                array_key_exists($name, $connections) || $name !== $default
                || !in_array(strtolower($name), ['database', 'file', 'redis'], true)
            ) {
                throw new \InvalidArgumentException("Queue connection [{$name}] is not defined.");
            }
            $connection = [];
        }

        $connection['driver'] ??= $name;
        return $connection;
    }
}
