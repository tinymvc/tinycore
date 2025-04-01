<?php

namespace Spark\Contracts\Database;

use PDO;
use PDOStatement;

interface DBContract
{
    public function getPdo(): PDO;

    public function query(string $query, ...$args): false|PDOStatement;

    public function prepare(string $statement, array $options = []): false|PDOStatement;
}