<?php

namespace Spark\Http\Session\Handler;

use PDO;
use PDOStatement;
use SessionHandlerInterface;
use Spark\Facades\DB;
use function base64_decode;
use function base64_encode;
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
class DatabaseHandler implements SessionHandlerInterface
{
    private PDO $pdo;

    private string $table;

    private int $lifetime;

    public function __construct(private array $config = [])
    {
        $this->table = (string) ($config['table'] ?? 'sessions');
        $this->lifetime = (int) ($config['lifetime'] ?? 120) * 60;
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

        if ($this->lifetime > 0 && $lastActivity < time() - $this->lifetime) {
            $this->destroy($id);
            return '';
        }

        $payload = (string) ($row['payload'] ?? '');

        return $payload === '' ? '' : (string) base64_decode($payload);
    }

    public function write(string $id, string $data): bool
    {
        $exists = (bool) $this->run(
            "SELECT 1 FROM {$this->table} WHERE id = ?",
            [$id]
        )->fetchColumn();

        $payload = base64_encode($data);
        $lastActivity = time();

        if ($exists) {
            $this->run(
                "UPDATE {$this->table} SET payload = ?, last_activity = ? WHERE id = ?",
                [$payload, $lastActivity, $id]
            );
        } else {
            $this->run(
                "INSERT INTO {$this->table} (id, payload, last_activity) VALUES (?, ?, ?)",
                [$id, $payload, $lastActivity]
            );
        }

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
            "DELETE FROM {$this->table} WHERE last_activity < ?",
            [time() - $max_lifetime]
        )->rowCount();
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