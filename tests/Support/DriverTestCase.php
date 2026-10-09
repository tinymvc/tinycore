<?php

require_once dirname(__DIR__) . '/Fixtures/Worker.php';

abstract class DriverTestCase extends \Spark\Testing\ApplicationTestCase
{
    protected function testStorageDirectory(): string
    {
        return dirname(__DIR__) . '/.cache/';
    }

    protected function createApplication(): \Spark\Foundation\Application
    {
        return \Spark\Foundation\Application::create($this->storagePath, [
            'app' => [
                'debug' => true,
                'key' => 'release-tests-only-32-character-secret',
                'url' => 'http://localhost',
                'storage_dir' => $this->storagePath,
                'temp_dir' => $this->storagePath . '/temp',
                'views_dir' => dirname(__DIR__, 2) . '/src/Foundation/resources/views',
            ],
            'database' => ['driver' => 'sqlite', 'file' => $this->storagePath . '/database.db'],
            'cache' => ['driver' => 'database'],
            'queue' => ['driver' => 'database'],
        ], providers: []);
    }

    protected function schema(): void
    {
        $migration = require dirname(__DIR__) . '/Fixtures/migrations/migration_2024_10_02_082930_framework.php';
        $migration->up();
    }

    protected function redisEnabled(): bool
    {
        return (bool) (getenv('SPARK_TEST_REDIS_SOCKET') ?: getenv('SPARK_TEST_REDIS_HOST'));
    }

    protected function tearDown(): void
    {
        try {
            if ($this->redisEnabled()) {
                $config = $this->redisConfig();

                // Each test owns a random prefix. Never flush the shared Redis database.
                foreach ([0, 1] as $database) {
                    $redis = \Spark\Utils\RedisConnector::make([...$config, 'database' => $database], 'test-cleanup');
                    $iterator = null;

                    do {
                        $keys = $redis->scan($iterator, $config['prefix'] . '*', 100);

                        if ($keys !== false && $keys !== []) {
                            $redis->del($keys);
                        }
                    } while ($iterator !== 0);
                }
            }
        } finally {
            parent::tearDown();
        }
    }

    protected function redisConfig(): array
    {
        return [
            'socket' => getenv('SPARK_TEST_REDIS_SOCKET') ?: null,
            'host' => getenv('SPARK_TEST_REDIS_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('SPARK_TEST_REDIS_PORT') ?: 6379),
            'password' => getenv('SPARK_TEST_REDIS_PASSWORD') ?: null,
            'prefix' => 'release-test-' . basename($this->storagePath),
        ];
    }

    protected function caches(): array
    {
        $drivers = [
            new \Spark\Cache\Storage\DatabaseStorage('test'),
            new \Spark\Cache\Storage\FileStorage('test', ['path' => $this->storagePath . '/cache']),
        ];

        if ($this->redisEnabled()) {
            $drivers[] = new \Spark\Cache\Storage\RedisStorage('test', $this->redisConfig());
        }

        return $drivers;
    }

    protected function queues(): array
    {
        $drivers = [
            new \Spark\Queue\Storage\DatabaseStorage(),
            new \Spark\Queue\Storage\FileStorage(['path' => $this->storagePath . '/queue']),
        ];

        if ($this->redisEnabled()) {
            $drivers[] = new \Spark\Queue\Storage\RedisStorage($this->redisConfig());
        }

        return $drivers;
    }
}
