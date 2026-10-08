<?php

require_once dirname(__DIR__) . '/Support/DriverTestCase.php';

final class MigrationTest extends DriverTestCase
{
    public function test_migration_ledger_and_seed_roundtrip(): void
    {
        $runner = new \Spark\Database\Migration(dirname(__DIR__) . '/Fixtures/migrations');
        ob_start();

        try {
            $runner->up(['seed' => true]);
            $runner->up(['seed' => true]);
            $this->assertSame(3, (int) app(\Spark\Database\DB::class)->query('SELECT COUNT(*) FROM migrations')->fetchColumn());
            $this->assertSame('John Doe', query('users')->where('email', 'admin@mail.com')->first()->name);
            $runner->down(['all' => true]);
            $this->assertSame(0, (int) app(\Spark\Database\DB::class)->query('SELECT COUNT(*) FROM migrations')->fetchColumn());
            $runner->up();
            $this->assertSame(2, (int) app(\Spark\Database\DB::class)->query('SELECT COUNT(*) FROM migrations')->fetchColumn());
        } finally {
            ob_end_clean();
        }
    }

    public function test_failed_migration_rolls_back_schema_and_ledger(): void
    {
        mkdir($this->storagePath . '/database/migrations', 0700, true);
        $runner = new \Spark\Database\Migration();
        file_put_contents($this->storagePath . '/database/migrations/migration_2021_failure.php', <<<'CODE'
<?php
return new class {
    public function up(): void
    {
        app(\Spark\Database\DB::class)->exec('CREATE TABLE rollback_probe (id INTEGER)');
        throw new \RuntimeException('Injected migration failure.');
    }
};
CODE);
        ob_start();

        try {
            $runner->up();
        } catch (\RuntimeException) {
        } finally {
            ob_end_clean();
        }

        $this->assertSame(0, (int) app(\Spark\Database\DB::class)->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'rollback_probe'")->fetchColumn());
        $this->assertSame(0, (int) app(\Spark\Database\DB::class)->query('SELECT COUNT(*) FROM migrations')->fetchColumn());
    }

    public function test_invalid_migration_methods_stop_the_run(): void
    {
        $directory = $this->storagePath . '/database/migrations';
        mkdir($directory, 0700, true);
        $file = $directory . '/migration_2026_invalid.php';
        file_put_contents($file, '<?php return null;');
        $runner = new \Spark\Database\Migration();
        ob_start();

        try {
            $blocked = false;

            try {
                $runner->up();
            } catch (\RuntimeException) {
                $blocked = true;
            }

            $this->assertTrue($blocked);
            $this->assertSame(0, (int) app(\Spark\Database\DB::class)->query('SELECT COUNT(*) FROM migrations')->fetchColumn());
            file_put_contents($file, <<<'CODE'
<?php
return new class {
    public function up(): void
    {
    }
};
CODE);
            $runner->up();
            $blocked = false;

            try {
                $runner->down(['all' => true]);
            } catch (\RuntimeException) {
                $blocked = true;
            }

            $this->assertTrue($blocked);
            $this->assertSame(1, (int) app(\Spark\Database\DB::class)->query('SELECT COUNT(*) FROM migrations')->fetchColumn());
        } finally {
            ob_end_clean();
        }
    }

    public function test_schema_roundtrip(): void
    {
        $migration = require dirname(__DIR__) . '/Fixtures/migrations/migration_2024_10_02_082930_framework.php';
        $migration->up();

        $pdo = app(\Spark\Database\DB::class)->getPdo();

        foreach (['sessions', 'caches', 'locks', 'jobs', 'failed_jobs'] as $table) {
            $this->assertSame('0', (string) $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn());
        }

        $migration->down();

        foreach (['sessions', 'caches', 'locks', 'jobs', 'failed_jobs'] as $table) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
            $stmt->execute([$table]);
            $this->assertSame(0, (int) $stmt->fetchColumn());
        }

        $migration->up();
    }
}
