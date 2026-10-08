<?php

require_once __DIR__ . '/FrameworkTestCase.php';
require_once dirname(__DIR__) . '/Fixtures/DatabaseScenarios.php';

use Spark\Database\Schema\Blueprint;
use Spark\Database\Schema\Schema;

abstract class DatabaseScenarioTestCase extends FrameworkTestCase
{
    private bool $databaseReady = false;

    protected function createApplication(): \Spark\Foundation\Application
    {
        $app = parent::createApplication();
        $driver = getenv('SPARK_TEST_DATABASE_DRIVER') ?: 'sqlite';

        if ($driver !== 'sqlite') {
            $prefix = 'SPARK_TEST_' . strtoupper($driver);
            $dsn = getenv($prefix . '_DSN') ?: '';

            if (!in_array($driver, ['mysql', 'pgsql'], true)
                || !preg_match('/(?:^|;)dbname=spark_test_[a-z0-9_]+(?:;|$)/', $dsn)) {
                throw new \LogicException('Database scenarios require a dedicated spark_test_* database.');
            }

            $app->instance(\Spark\Database\DB::class, new \Spark\Database\DB([
                'driver' => $driver,
                'dsn' => $dsn,
                'user' => getenv($prefix . '_USER') ?: '',
                'password' => getenv($prefix . '_PASSWORD') ?: '',
            ]));
        }

        return $app;
    }

    protected function tearDown(): void
    {
        try {
            if ($this->databaseReady) {
                foreach (['scenario_role_user', 'scenario_roles', 'scenario_schema', 'scenario_renamed', 'scenario_comments', 'scenario_posts', 'scenario_users'] as $table) {
                    Schema::dropIfExists($table);
                }
            }
        } finally {
            parent::tearDown();
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->databaseReady = true;

        Schema::create('scenario_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        Schema::create('scenario_posts', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id')->nullable();
            $table->string('title');
            $table->integer('score')->default(0);
            $table->string('status')->default('draft');
            $table->text('metadata')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->softDeletes();
        });

        Schema::create('scenario_comments', function (Blueprint $table) {
            $table->id();
            $table->integer('post_id');
            $table->string('body');
        });

        foreach (['Ada', 'Grace', 'Linus'] as $name) {
            ScenarioUser::create(['name' => $name]);
        }

        foreach ([
            [1, 'Alpha', 0, 'draft', null],
            [1, 'Beta', 10, 'published', '2025-01-15 12:00:00'],
            [2, 'Gamma', 20, 'published', '2026-02-20 12:00:00'],
            [2, 'Delta', 30, 'draft', null],
            [null, 'Orphan', 40, 'published', '2026-02-21 12:00:00'],
        ] as [$user, $title, $score, $status, $published]) {
            ScenarioPost::create([
                'user_id' => $user,
                'title' => $title,
                'score' => $score,
                'status' => $status,
                'published_at' => $published,
                'metadata' => ['shared' => true, 'tags' => ['php']],
            ]);
        }

        foreach ([[1, 'First'], [1, 'Second'], [3, 'Third']] as [$post, $body]) {
            ScenarioComment::create(['post_id' => $post, 'body' => $body]);
        }
    }

    protected function ids($query): array
    {
        return array_map('intval', $query->orderBy('scenario_posts.id')->pluck('scenario_posts.id'));
    }
}
