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

    public function test_migrate_status_batch_rollback_and_db_seed(): void
    {
        $directory = $this->storagePath . '/database/migrations';
        mkdir($directory, 0700, true);

        file_put_contents($directory . '/migration_2025_01_01_000001_first.php', <<<'CODE'
<?php
return new class {
    public function up(): void { app(\Spark\Database\DB::class)->exec('CREATE TABLE batch_first (id INTEGER PRIMARY KEY)'); }
    public function down(): void { app(\Spark\Database\DB::class)->exec('DROP TABLE IF EXISTS batch_first'); }
};
CODE);
        file_put_contents($directory . '/migration_2025_01_01_000002_second.php', <<<'CODE'
<?php
return new class {
    public function up(): void { app(\Spark\Database\DB::class)->exec('CREATE TABLE batch_second (id INTEGER PRIMARY KEY)'); }
    public function down(): void { app(\Spark\Database\DB::class)->exec('DROP TABLE IF EXISTS batch_second'); }
};
CODE);

        $runner = new \Spark\Database\Migration($directory);

        // Status before migrations table exists stays read-only and reports Pending.
        ob_start();
        try {
            $runner->status();
            $beforeOutput = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $this->assertFalse(\Spark\Database\Schema\Schema::hasTable('migrations'));
        $this->assertStringContainsString('Pending', $beforeOutput);
        $this->assertStringContainsString('Showing 0 ran, 2 pending.', $beforeOutput);

        // Batch 1: apply first two migrations.
        ob_start();
        try {
            $runner->up();
        } finally {
            ob_end_clean();
        }

        // Add Batch 2 (two more migrations) + a seeder.
        file_put_contents($directory . '/migration_2025_01_02_000003_third.php', <<<'CODE'
<?php
return new class {
    public function up(): void { app(\Spark\Database\DB::class)->exec('CREATE TABLE batch_third (id INTEGER PRIMARY KEY)'); }
    public function down(): void { app(\Spark\Database\DB::class)->exec('DROP TABLE IF EXISTS batch_third'); }
};
CODE);
        file_put_contents($directory . '/migration_2025_01_02_000004_fourth.php', <<<'CODE'
<?php
return new class {
    public function up(): void { app(\Spark\Database\DB::class)->exec('CREATE TABLE batch_fourth (id INTEGER PRIMARY KEY)'); }
    public function down(): void { app(\Spark\Database\DB::class)->exec('DROP TABLE IF EXISTS batch_fourth'); }
};
CODE);
        file_put_contents($directory . '/seed_2025_01_02_000005_items.php', <<<'CODE'
<?php
return new class {
    public function up(): void
    {
        $pdo = app(\Spark\Database\DB::class)->getPdo();
        $pdo->exec('CREATE TABLE IF NOT EXISTS seeded_items (name TEXT)');
        $pdo->exec("INSERT INTO seeded_items (name) VALUES ('seed-row')");
    }
};
CODE);

        ob_start();
        try {
            $runner->up();
            $runner->status();
            $midOutput = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $this->assertStringContainsString('Showing 4 ran, 1 pending.', $midOutput);

        // Default rollback drops ONLY the latest batch (batch 2: third + fourth).
        ob_start();
        try {
            $runner->down();
        } finally {
            ob_end_clean();
        }
        $this->assertTrue(\Spark\Database\Schema\Schema::hasTable('batch_first'));
        $this->assertTrue(\Spark\Database\Schema\Schema::hasTable('batch_second'));
        $this->assertFalse(\Spark\Database\Schema\Schema::hasTable('batch_third'));
        $this->assertFalse(\Spark\Database\Schema\Schema::hasTable('batch_fourth'));
        $this->assertSame(2, (int) app(\Spark\Database\DB::class)->query('SELECT COUNT(*) FROM migrations')->fetchColumn());

        // Standalone db:seed can run repeatedly and accepts a target filter.
        ob_start();
        try {
            $runner->seed(['_args' => ['items']]);
            $runner->seed(['class' => 'items']);
        } finally {
            ob_end_clean();
        }
        $this->assertSame(2, (int) app(\Spark\Database\DB::class)->query('SELECT COUNT(*) FROM seeded_items')->fetchColumn());
    }
}
