<?php

namespace Spark\Http\Session\Handler;

use PDO;
use PDOStatement;
use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;
use Spark\Facades\DB;
use function base64_decode;
use function base64_encode;
use function in_array;
use function is_int;
use function is_string;
use function time;

/**
 * Class DatabaseHandler
 *
 * Persists session data to a database table using raw PDO statements.
 * Payloads are base64-encoded for safe storage in a TEXT column regardless of charset.
 *
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class DatabaseHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    private PDO $pdo;

    private string $table;

    private string $driver;

    private int $lifetime;

    public function __construct(private array $config = [])
    {
        $this->table = (string) ($config['table'] ?? 'sessions');

        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $this->table)) {
            throw new \InvalidArgumentException('Invalid session table name.');
        }
        $this->lifetime = max(1, (int) ($config['lifetime'] ?? 120)) * 60;
    }

    public function open(string $path, string $name): bool
    {
        $connection = $this->config['connection'] ?? null;

        try {
            $this->pdo = is_string($connection) && $connection !== ''
                ? DB::connection($connection)->getPdo()
                : DB::getPdo();
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to connect to the database: ' . $e->getMessage());
        }

        $this->driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if (!in_array($this->driver, ['mysql', 'pgsql', 'sqlite'], true)) {
            throw new \RuntimeException('Unsupported session database driver.');
        }

        $quote = $this->driver === 'mysql' ? '`' : '"';
        $this->table = implode('.', array_map(
            static fn(string $part): string => $quote . $part . $quote,
            explode('.', (string) ($this->config['table'] ?? 'sessions')),
        ));

        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $row = $this->run(
            "SELECT payload, last_activity FROM {$this->table} WHERE id = ?",
            [$id]
        )->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return '';
        }

        $lastActivity = (int) ($row['last_activity'] ?? 0);

        if ($this->lifetime > 0 && $lastActivity <= time() - $this->lifetime) {
            return '';
        }

        $payload = (string) ($row['payload'] ?? '');

        return $payload === '' ? '' : (string) base64_decode($payload);
    }

    public function write(string $id, string $data): bool
    {
        $sql = "INSERT INTO {$this->table} (id, payload, last_activity) VALUES (?, ?, ?)";
        $sql .= $this->driver === 'mysql'
            ? ' ON DUPLICATE KEY UPDATE payload = VALUES(payload), last_activity = VALUES(last_activity)'
            : ' ON CONFLICT (id) DO UPDATE SET payload = excluded.payload, last_activity = excluded.last_activity';

        $this->run($sql, [$id, base64_encode($data), time()]);

        return true;
    }

    public function destroy(string $id): bool
    {
        $this->run("DELETE FROM {$this->table} WHERE id = ?", [$id]);

        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return $this->run(
            "DELETE FROM {$this->table} WHERE last_activity <= ?",
            [time() - $max_lifetime]
        )->rowCount();
    }

    public function validateId(string $id): bool
    {
        return $this->run(
            "SELECT 1 FROM {$this->table} WHERE id = ? AND last_activity > ?",
            [$id, time() - $this->lifetime],
        )->fetchColumn() !== false;
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        return $this->write($id, $data);
    }

    /**
     * Prepare, bind (ints as PARAM_INT) and execute.
     */
    private function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);

        foreach (array_values($params) as $i => $value) {
            $stmt->bindValue($i + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        $stmt->execute();

        return $stmt;
    }
}