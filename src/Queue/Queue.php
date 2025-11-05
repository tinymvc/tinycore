<?php

namespace Spark\Queue;

use Closure;
use DateTime;
use Laravel\SerializableClosure\SerializableClosure;
use RuntimeException;
use Spark\Console\Prompt;
use Spark\Queue\Contracts\QueueContract;
use Spark\EventDispatcher;
use Spark\Queue\Exceptions\FailedToLoadJobsException;
use Spark\Queue\Exceptions\FailedToSaveJobsException;
use Spark\Queue\Exceptions\InvalidStorageFileException;
use Spark\Support\Traits\Macroable;

/**
 * A job queue that stores the jobs in a JSON file.
 *
 * This class uses the {@see Job} class to store the jobs in a JSON file.
 * The queue is saved to a file on the disk, and the jobs are loaded from
 * the file when the queue is constructed.
 * 
 * @package Spark\Queue
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Queue implements QueueContract
{
    use Macroable;

    /**
     * @var array<int, array> The array of jobs to be run.
     */
    private array $jobs = [];

    /**
     * @var bool Whether the jobs have been changed since the last save.
     */
    private bool $isChanged = false;

    /**
     * Constructs a new instance of the queue.
     *
     * @param string|null $storageFile The path to the queue file. Defaults to
     *                                  storage_dir('temp/queue.json').
     * @param string|null $logFile The path to the log file. Defaults to
     *                                 storage_dir('queue.log').
     */
    public function __construct(private ?string $storageFile = null, private ?string $logFile = null)
    {
        $this->storageFile ??= storage_dir('queue.json');
        $this->logFile ??= storage_dir('logs/queue.log');

        // Ensure that the queue and log files exist and are writable.
        $this->makeSureQueueFileIsValid($this->storageFile);
        $this->makeSureQueueFileIsValid($this->logFile);

        // Set the serializable closure secret key.
        if (class_exists(SerializableClosure::class)) {
            SerializableClosure::setSecretKey(config('app_key'));
        }

        // Load the existing jobs from the queue file.
        $this->loadJobs();
    }

    /**
     * Adds a job to the queue.
     *
     * @param Job $job The job to be added.
     */
    public function addJob(Job $job, ?string $id = null): void
    {
        // Add the job to the array of jobs.
        if ($id) {
            $this->jobs[$id] = $this->serializeJob($job);
        } else {
            $this->jobs[] = $this->serializeJob($job);
        }
        $this->isChanged = true; // Set the changed flag to true.
    }

    /**
     * Clears all jobs from the queue.
     *
     * This method will remove all jobs from the queue and mark the queue as changed.
     *
     * @return void
     */
    public function clearAllJobs(): void
    {
        $this->jobs = [];
        $this->isChanged = true;
    }

    /**
     * Clears all repeated jobs from the queue.
     *
     * This method will filter out jobs that are marked to be repeated from the
     * queue and mark the queue as changed.
     *
     * @return void
     */
    public function clearRepeatedJobs(): void
    {
        $this->jobs = array_filter($this->jobs, fn($job) => !$job['repeat']);
        $this->isChanged = true;
    }

    /**
     * Gets a job from the queue by its ID.
     *
     * This method will return the job with the given ID from the queue. If the
     * job does not exist, it will return null.
     *
     * @param string $id The ID of the job to be retrieved.
     *
     * @return Job|null The job with the given ID or null if it does not exist.
     */
    public function getJob(string $id): ?Job
    {
        // Get the job from the queue by its ID.
        $job = $this->jobs[$id] ?? null;

        // If there is no job, return null.
        if (!$job) {
            return null;
        }

        // Unserialize the job and return it.
        return $this->unserializeJob($job);
    }

    /**
     * Removes a job from the queue by its ID.
     *
     * This method will remove the job with the given ID from the queue and mark
     * the queue as changed.
     *
     * @param string $id The ID of the job to be removed.
     *
     * @return void
     */
    public function removeJob(string $id): void
    {
        unset($this->jobs[$id]);
        $this->isChanged = true;
    }

    /**
     * Runs the jobs in the queue.
     *
     * This method will iterate over the jobs in the queue and execute them if
     * their scheduled time is in the past. If the job is repeated, it will be
     * rescheduled for the next time. If the job is not repeated, it will be
     * removed from the queue.
     * 
     * @param int $maxJobs The maximum number of jobs to run in this execution.
     * @return void
     */
    public function run(int $maxJobs): void
    {
        if (empty($this->getJobs())) {
            return;
        }

        $now = new DateTime();

        $ranJobs = 0; // Counter for the number of jobs run.
        $failedJobs = 0; // Counter for the number of failed jobs.

        // Measure the start time and memory usage.
        $startedAt = microtime(true);
        $startedMemory = memory_get_usage(true);

        foreach ($this->getJobs() as $id => $serializedJob) {
            // If the maximum number of jobs to run has been reached, break the loop.
            if ($ranJobs >= $maxJobs) {
                break;
            }

            $job = $this->unserializeJob($serializedJob);

            // If the job is scheduled for the past, execute it.
            if ($job->getScheduledTime() <= $now) {
                // Otherwise, remove it from the queue.
                unset($this->jobs[$id]);

                $this->save(); // Save the jobs to the queue file.

                if (is_cli()) {
                    Prompt::message("Running job <bold>#$id</bold>", 'info');
                }

                try {
                    $job->handle(); // Execute the job.

                    // If the job is repeated, reschedule it for the next time.
                    if ($job->isRepeated()) {
                        $job->schedule(new DateTime($job->getRepeat()));
                        $this->addJob($job, $id);
                    }

                    $ranJobs++; // Increment the counter for the number of jobs run.
                } catch (\Throwable $e) {
                    if (is_cli()) {
                        Prompt::message("Job <bold>#$id</bold> failed: " . $e->getMessage(), 'error');
                    }

                    $failedJobs++; // Increment the counter for the number of failed jobs.
                }
            }
        }

        $timeUsed = microtime(true) - $startedAt;
        $memoryUsed = memory_get_usage(true) - $startedMemory;

        // If any jobs were run, display the time and memory used.
        $this->addQueueLog($timeUsed, $memoryUsed, $ranJobs, $failedJobs);
    }

    /**
     * Returns the jobs in the queue.
     *
     * @return array<int, array> The array of jobs in the queue.
     */
    public function getJobs(): array
    {
        return $this->jobs ??= [];
    }

    /**
     * Loads the jobs from the queue file.
     *
     * This method will read the data from the queue file and create an array of
     * Job objects. The data from the file is decoded from JSON and the callback
     * is evaluated back into a callable.
     *
     * @return void
     */
    private function loadJobs(): void
    {
        $rawJobs = file_get_contents($this->storageFile);

        if ($rawJobs === false) {
            throw new FailedToLoadJobsException('Failed to load jobs.');
        }

        $jobs = (array) json_decode($rawJobs, true);

        $this->jobs = array_merge($this->jobs, $jobs);
    }

    /**
     * Serializes a Job object into an array that can be saved to JSON.
     *
     * This method takes a Job object and returns an array of data that can be saved
     * to JSON. The callback is serialized into a string and the scheduled time is
     * saved as a string. The repeat and priority are saved as they are. The event
     * listeners are serialized into an array of arrays, where each inner array
     * contains the priority and callback of the event listener.
     *
     * @param Job $job The Job object to be serialized.
     *
     * @return array The serialized Job object as an array.
     */
    private function serializeJob(Job $job): array
    {
        return [
            'callback' => $this->serializeCallback($job->getCallback()), // Serialize the callback and save it.
            'scheduledTime' => $job->getScheduledTime()->format('c'), // Save the scheduled time as string.
            'repeat' => $job->getRepeat(), // Save the repeat as it is.
            'priority' => $job->getPriority(), // Save the priority as it is.
            'eventListeners' => collect(
                $job->getEventDispatcher()->getListeners()
            )
                ->mapWithKeys(fn($events, $eventName) => [
                    $eventName => array_map(
                        fn($event) => [
                            'priority' => $event['priority'],
                            'callback' => $this->serializeCallback($event['callback'])
                        ],
                        $events
                    )
                ])
                ->all(), // Save the events as it is.
        ];
    }

    /**
     * Unserializes a serialized Job object into a Job object.
     *
     * This method takes a serialized Job object and returns a Job object.
     * The callback is unserialized into a callable and the scheduled time is
     * set using the ISO 8601 string. The repeat and priority are set as they
     * are. The event listeners are unserialized into an array of arrays, where
     * each inner array contains the priority and callback of the event listener.
     *
     * @param array $job The serialized Job object to be unserialized.
     *
     * @return Job The unserialized Job object.
     */
    private function unserializeJob(array $job): Job
    {
        return new Job(
            $this->unserializeCallback($job['callback']), // Unserialize the callback and set it.
            new DateTime($job['scheduledTime']), // Set the scheduled time using the ISO 8601 string.
            $job['repeat'], // Set the repeat as it is.
            new EventDispatcher(
                collect($job['eventListeners'])
                    ->mapWithKeys(fn($events, $eventName) => [
                        $eventName => array_map(
                            fn($event) => [
                                'priority' => $event['priority'],
                                'callback' => $this->unserializeCallback($event['callback'])
                            ], // Unserialize the callback and set it.
                            $events
                        )
                    ])
                    ->all() // Set the event listeners as they are.
            ),
            $job['priority'] // Set the priority as it is.
        );
    }

    /**
     * Serializes a callback to a string.
     *
     * If the callback is an anonymous function, it is serialized using a
     * SerializableClosure. Otherwise, it is serialized using var_export.
     *
     * @param string|array|callable $callback
     *   The callback to be serialized.
     *
     * @return string
     *   The serialized string representation of the callback.
     */
    private function serializeCallback(string|array|callable $callback): string
    {
        if ($callback instanceof Closure) {
            // If the callback is a Closure, check if the SerializableClosure package is installed.
            // If not, throw an exception.
            if (!class_exists(SerializableClosure::class)) {
                throw new RuntimeException(
                    'SerializableClosure package not found, please ' .
                    'run: composer require laravel/serializable-closure to install it.'
                );
            }

            // If the callback is an anonymous function, serialize it using a SerializableClosure.
            $callback = serialize(new SerializableClosure($callback));
        } else {
            // Otherwise, serialize it using var_export.
            $callback = var_export($callback, return: true);
        }

        return $callback;
    }

    /**
     * Unserializes a callback from a string.
     *
     * If the callback is a string representation of a SerializableClosure, it is
     * unserialized using unserialize. Otherwise, it is unserialized using eval.
     *
     * @param string $callback
     *   The string representation of the callback to be unserialized.
     *
     * @return string|array|callable
     *   The unserialized callback.
     */
    private function unserializeCallback(string $callback): string|array|callable
    {
        if (strpos($callback, 'SerializableClosure') !== false) {
            // If the callback is serialized, unserialize it using unserialize.
            $callback = unserialize($callback)->getClosure();
        } else {
            // Otherwise, unserialize it using eval.
            $callback = eval ("return $callback;");
        }

        return $callback;
    }

    /**
     * Saves the jobs to the queue file.
     *
     * This method will take the current array of jobs in the queue and save them
     * to the queue file. The jobs are converted to an array of data that can be
     * saved to JSON. The Callback is converted to a string by var_exporting it.
     * The scheduled time and repeat are saved as is.
     *
     * @return void
     */
    public function save(): void
    {
        if (!$this->isChanged) {
            return; // If there are no changes, do nothing.
        }

        // Convert the jobs to an array of data that can be saved to JSON.
        $jobs = collect($this->getJobs())
            ->sortByDesc('priority');

        // Save the jobs to the queue file.
        $isSaved = file_put_contents($this->storageFile, $jobs->toJson(), LOCK_EX);

        if ($isSaved === false) {
            throw new FailedToSaveJobsException('Failed to save jobs.');
        }

        $this->isChanged = false; // Set the changed flag to false.
    }

    /**
     * Adds a log entry to the queue log file.
     *
     * This method will add a log entry to the queue log file with the time
     * taken to run the jobs, the memory used, and the number of jobs run.
     * The log entry is added to the beginning of the log file and only the
     * latest 5000 entries are kept.
     *
     * @param float $timeUsed The time taken to run the jobs in milliseconds.
     * @param int $memoryUsed The memory used to run the jobs in bytes.
     * @param int $ranJobs The number of jobs that were run.
     *
     * @return void
     */
    private function addQueueLog(float $timeUsed, int $memoryUsed, int $ranJobs, int $failedJobs): void
    {
        $maxFileSize = 5 * 1024 * 1024; // 5 MB in bytes

        // Check if log file exists and its size and rotate if it exceeds the max size.
        if (is_file($this->logFile) && filesize($this->logFile) >= $maxFileSize) {
            rename($this->logFile, $this->logFile . '.' . date('Y-m-d_H-i-s'));
        }

        $logEntry = sprintf(
            "[%s] Finished running %d success and %d failed job(s) in %.4f seconds, using %.2f MB of memory.\n",
            date('Y-m-d H:i:s'),
            $ranJobs,
            $failedJobs,
            $timeUsed,
            $memoryUsed / 1024 / 1024
        );

        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Ensures that the queue file exists and is writable.
     *
     * This method checks if the queue file exists. If it does not exist, it
     * attempts to create it. If it exists but is not writable, it attempts to
     * change its permissions to make it writable. If any of these operations
     * fail, an InvalidStorageFileException is thrown.
     *
     * @param string $queueFile The path to the queue file.
     *
     * @throws InvalidStorageFileException If the queue file cannot be created
     *                                     or made writable.
     *
     * @return void
     */
    private function makeSureQueueFileIsValid(string $queueFile): void
    {
        // If the queue file does not exist, try to create it.
        if (!file_exists($queueFile) && !touch($queueFile)) {
            throw new InvalidStorageFileException('Failed to create the queue file.');
        }
        // If the queue file is not writable, try to make it so.
        elseif (!is_writable($queueFile) && !chmod($queueFile, 0666)) {
            throw new InvalidStorageFileException(
                sprintf('The queue file (%s) is not writable.', $queueFile)
            );
        }
    }

    /**
     * Destructs the object and saves the jobs to the queue file.
     *
     * If the jobs have been changed since the last save, this method will save
     * the jobs to the queue file. The jobs are converted to an array of data
     * that can be saved to JSON. The closure is converted to a string by
     * var_exporting it. The scheduled time and repeat are saved as they are.
     *
     * @return void
     */
    public function __destruct()
    {
        $this->isChanged && $this->save();
    }
}
