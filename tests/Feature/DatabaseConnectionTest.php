<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

use Spark\Database\DB;
use Spark\Database\Exceptions\InvalidDatabaseConfigException;

final class DatabaseConnectionTest extends FrameworkTestCase
{
    public function test_default_connection_is_shared(): void
    {
        $this->assertSame(app(DB::class), DB::connection());
        $this->assertSame(app(DB::class), DB::connection('sqlite'));
    }

    public function test_undefined_named_connection_fails_clearly(): void
    {
        $this->assertThrows(InvalidDatabaseConfigException::class, fn () => DB::connection('missing'));
    }

    public function test_named_config_overrides_base_and_normalizes_aliases(): void
    {
        $db = new DB(['default' => 'primary', 'host' => 'base', 'connections' => ['primary' => ['driver' => 'PGSQL', 'host' => 'selected', 'username' => 'tester', 'database' => 'example']]]);
        $this->assertSame('pgsql', $db->getDriver());
        $this->assertSame('selected', $db->getConfig('host'));
        $this->assertSame('tester', $db->getConfig('user'));
        $this->assertSame('example', $db->getConfig('name'));
    }

    public function test_reset_replaces_connection_and_discards_old_memory_database(): void
    {
        $db = new DB(['driver' => 'sqlite', 'database' => ':memory:']);
        $old = $db->getPdo();
        $db->exec('CREATE TABLE old_table (id INTEGER)');
        $db->reset(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->assertNotSame($old, $db->getPdo());
        $this->assertSame(0, (int) $db->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'old_table'")->fetchColumn());
        $this->assertSame(1, (int) $db->query('PRAGMA foreign_keys')->fetchColumn());
    }

    public function test_file_alias_creates_parent_directory_and_persists_data(): void
    {
        $config = ['driver' => 'sqlite', 'file' => $this->storagePath . '/nested/db/data.sqlite'];
        $db = new DB($config);
        $db->exec('CREATE TABLE example (value TEXT)');
        $db->statement('INSERT INTO example VALUES (?)', ['persistent']);
        $this->assertSame('persistent', (new DB($config))->query('SELECT value FROM example')->fetchColumn());
    }

    public function test_statement_binds_quotes_and_binary_data(): void
    {
        $db = app(DB::class);
        $db->exec('CREATE TABLE example (value TEXT)');
        $value = "quote'\0binary";
        $this->assertTrue($db->statement('INSERT INTO example VALUES (?)', [$value]));
        $this->assertSame($value, $db->query('SELECT value FROM example')->fetchColumn());
    }
}
