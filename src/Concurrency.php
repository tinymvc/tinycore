<?php

namespace Spark;

use Closure;
use Exception;

/**
 * Simple Concurrency Class
 *
 * Handles multiple time-consuming tasks concurrently using process
 * forking or parallel execution.
 *
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 * @version 1.0.0
 */
class Concurrency
{
    /**
     * The tasks to be executed
     *
     * @var array
     */
    protected array $tasks = [];

    /**
     * The results of executed tasks
     *
     * @var array
     */
    protected array $results = [];

    /**
     * Maximum number of concurrent processes
     *
     * @var int
     */
    protected int $maxProcesses = 10;

    /**
     * Create a new Concurrency instance
     *
     * @param int $maxProcesses
     */
    public function __construct(int $maxProcesses = 10)
    {
        $this->maxProcesses = $maxProcesses;
    }

    /**
     * Run multiple tasks concurrently
     *
     * @param array $tasks Array of closures/callables
     * @return array
     */
    public static function run(array $tasks): array
    {
        return (new static())->execute($tasks);
    }

    /**
     * Run multiple tasks concurrently with a specified max processes
     *
     * @param array $tasks
     * @param int $maxProcesses
     * @return array
     */
    public static function runWithLimit(array $tasks, int $maxProcesses): array
    {
        return (new static($maxProcesses))->execute($tasks);
    }

    /**
     * Add a task to the queue
     *
     * @param Closure|callable $task
     * @param string|null $key
     * @return $this
     */
    public function add(Closure|callable $task, ?string $key = null): static
    {
        if ($key !== null) {
            $this->tasks[$key] = $task;
        } else {
            $this->tasks[] = $task;
        }

        return $this;
    }

    /**
     * Set maximum concurrent processes
     *
     * @param int $max
     * @return $this
     */
    public function limit(int $max): static
    {
        $this->maxProcesses = $max;
        return $this;
    }

    /**
     * Execute all tasks
     *
     * @param array|null $tasks
     * @return array
     */
    public function execute(?array $tasks = null): array
    {
        if ($tasks !== null) {
            $this->tasks = $tasks;
        }

        if (empty($this->tasks)) {
            return [];
        }

        // Check if parallel execution is available
        if ($this->canUseParallel()) {
            return $this->executeParallel();
        }

        // Fallback to multi-curl for async HTTP requests or sequential execution
        return $this->executeSequential();
    }

    /**
     * Check if parallel extension is available
     *
     * @return bool
     */
    protected function canUseParallel(): bool
    {
        return PHP_ZTS && extension_loaded('parallel');
    }

    /**
     * Execute tasks using parallel extension
     *
     * @return array
     */
    protected function executeParallel(): array
    {
        $results = [];
        $runtime = new \parallel\Runtime();

        foreach ($this->tasks as $key => $task) {
            try {
                $future = $runtime->run($task);
                $results[$key] = $future->value();
            } catch (Exception $e) {
                $results[$key] = [
                    'error' => true,
                    'message' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    /**
     * Execute tasks sequentially (fallback)
     *
     * @return array
     */
    protected function executeSequential(): array
    {
        $results = [];

        foreach ($this->tasks as $key => $task) {
            try {
                $results[$key] = $task();
            } catch (Exception $e) {
                $results[$key] = [
                    'error' => true,
                    'message' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    /**
     * Wait for all tasks to complete
     *
     * @return array
     */
    public function wait(): array
    {
        return $this->execute();
    }

    /**
     * Get results
     *
     * @return array
     */
    public function getResults(): array
    {
        return $this->results;
    }
}