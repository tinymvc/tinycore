<?php

namespace Spark\Database;

/** @internal Connection and table namespace retained by hydrated models. */
final class ModelContext
{
    private ?DB $database;
    private ?string $fingerprint = null;

    private static array $queryContexts = [];
    private static ?\WeakMap $fiberQueryContexts = null;

    public function __construct(DB $database, public readonly string $prefix, bool $snapshot = true)
    {
        // Compilation can inherit context without opening a connection.
        if (!$snapshot) {
            $this->database = $database;
            return;
        }
        // Share the active PDO (including transactions), but survive DB::reset().
        $database->getPdo();
        $this->database = clone $database;
        $this->fingerprint = $database->connectionFingerprint();
    }

    /** Scope context to a model's query factory, including application overrides. */
    public function queryFor(string $model, \Closure $factory): QueryBuilder
    {
        $contexts = &self::queryContexts();
        $previous = $contexts[$model] ?? null;
        $contexts[$model] = $this;

        try {
            return $factory();
        } finally {
            if ($previous === null) {
                unset($contexts[$model]);
            } else {
                $contexts[$model] = $previous;
            }
        }
    }

    public static function currentQueryContext(string $model): ?self
    {
        $contexts = &self::queryContexts();
        return $contexts[$model] ?? null;
    }

    /** A suspended Fiber must not change another request's query context. */
    private static function &queryContexts(): array
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            return self::$queryContexts;
        }

        self::$fiberQueryContexts ??= new \WeakMap();
        $state = self::$fiberQueryContexts[$fiber] ??= (object) ['contexts' => []];

        return $state->contexts;
    }

    public function database(): DB
    {
        if ($this->database !== null) {
            return $this->database;
        }

        $default = DB::connection();
        if ($default->connectionFingerprint() === $this->fingerprint) {
            $default->getPdo();
            return $this->database = clone $default;
        }

        foreach (array_keys((array) config('database.connections', [])) as $name) {
            $database = DB::connection($name);
            if ($database->connectionFingerprint() === $this->fingerprint) {
                $database->getPdo();
                return $this->database = $database;
            }
        }

        throw new \LogicException('The serialized model database connection is no longer configured.');
    }

    public function key(): string
    {
        return spl_object_id($this->database()->getPdo()) . ':' . $this->prefix;
    }

    public function __serialize(): array
    {
        // Never place PDO instances or database credentials in cached models.
        return ['fingerprint' => $this->fingerprint ?? $this->database()->connectionFingerprint(), 'prefix' => $this->prefix];
    }

    public function __unserialize(array $data): void
    {
        $this->database = null;
        $this->fingerprint = $data['fingerprint'];
        $this->prefix = $data['prefix'];
    }
}
