<?php

namespace Spark;

use Closure;
use InvalidArgumentException;
use Throwable;

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
     * @throws InvalidArgumentException
     */
    public function __construct(int $maxProcesses = 10)
    {
        if ($maxProcesses < 1) {
            throw new InvalidArgumentException('Maximum processes must be at least 1');
        }

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
     * @throws InvalidArgumentException
     */
    public function limit(int $max): static
    {
        if ($max < 1) {
            throw new InvalidArgumentException('Maximum processes must be at least 1');
        }

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
            return $this->results = []; // No tasks to execute
        }

        // Check if parallel execution is available
        if ($this->canUseParallel()) {
            $this->results = $this->executeParallel();
        } else {
            $this->results = $this->executeSequential();
        }

        return $this->results;
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
        $futures = [];
        $runtimes = [];
        $taskKeys = array_keys($this->tasks);
        $taskValues = array_values($this->tasks);
        $totalTasks = count($this->tasks);
        $currentIndex = 0;

        // Start initial batch of tasks up to maxProcesses limit
        while ($currentIndex < min($this->maxProcesses, $totalTasks)) {
            $key = $taskKeys[$currentIndex];
            $task = $taskValues[$currentIndex];

            try {
                $runtime = new \parallel\Runtime();
                $futures[$key] = $runtime->run($task);
                $runtimes[$key] = $runtime;
            } catch (Throwable $e) {
                $results[$key] = [
                    'error' => true,
                    'message' => $e->getMessage()
                ];
            }

            $currentIndex++;
        }

        // Wait for tasks to complete and start new ones
        while (!empty($futures)) {
            foreach ($futures as $key => $future) {
                try {
                    // Check if future is done (non-blocking check)
                    if ($future->done()) {
                        $results[$key] = $future->value();
                        unset($futures[$key]);
                        unset($runtimes[$key]);

                        // Start a new task if available
                        if ($currentIndex < $totalTasks) {
                            $newKey = $taskKeys[$currentIndex];
                            $newTask = $taskValues[$currentIndex];

                            try {
                                $runtime = new \parallel\Runtime();
                                $futures[$newKey] = $runtime->run($newTask);
                                $runtimes[$newKey] = $runtime;
                            } catch (Throwable $e) {
                                $results[$newKey] = [
                                    'error' => true,
                                    'message' => $e->getMessage()
                                ];
                            }

                            $currentIndex++;
                        }
                    }
                } catch (Throwable $e) {
                    $results[$key] = [
                        'error' => true,
                        'message' => $e->getMessage()
                    ];
                    unset($futures[$key]);
                    unset($runtimes[$key]);
                }
            }

            // Small sleep to prevent busy waiting
            if (!empty($futures)) {
                usleep(1000); // 1ms
            }
        }

        // Cleanup any remaining runtime instances
        unset($runtimes);

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
            } catch (Throwable $e) {
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

    /**
     * Get tasks
     *
     * @return array
     */
    public function getTasks(): array
    {
        return $this->tasks;
    }

    /**
     * Get maximum concurrent processes
     *
     * @return int
     */
    public function getMaxProcesses(): int
    {
        return $this->maxProcesses;
    }

    /**
     * Clear all tasks
     *
     * @return void
     */
    public function clearTasks(): void
    {
        $this->tasks = [];
    }

    /**
     * Clear all results
     *
     * @return void
     */
    public function clearResults(): void
    {
        $this->results = [];
    }

    /**
     * Reset tasks and results
     *
     * @return void
     */
    public function reset(): void
    {
        $this->tasks = [];
        $this->results = [];
        $this->maxProcesses = 10;
    }
}