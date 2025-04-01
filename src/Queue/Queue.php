<?php

namespace Spark\Queue;

use DateTime;
use Laravel\SerializableClosure\SerializableClosure;
use Spark\Contracts\Queue\QueueContract;
use Spark\Queue\Exceptions\FailedToLoadJobsException;
use Spark\Queue\Exceptions\FailedToSaveJobsException;
use Spark\Queue\Exceptions\InvalidStorageFileException;
use Spark\Utils\EventDispatcher;

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
     */
    public function __construct(private ?string $storageFile = null)
    {
        $this->storageFile ??= storage_dir('queue.json');

        // If the queue file does not exist, try to create it.
        if (!file_exists($this->storageFile) && !touch($this->storageFile)) {
            throw new InvalidStorageFileException('Failed to create the queue file.');
        }
        // If the queue file is not writable, try to make it so.
        elseif (!is_writable($this->storageFile) && !chmod($this->storageFile, 0666)) {
            throw new InvalidStorageFileException(
                sprintf('The queue file (%s) is not writable.', $this->storageFile)
            );
        }

        // Set the serializable closure secret key.
        SerializableClosure::setSecretKey(config('app_key'));

        // Load the existing jobs from the queue file.
        $this->loadJobs();
    }

    /**
     * Adds a job to the queue.
     *
     * @param Job $job The job to be added.
     */
    public function addJob(Job $job): void
    {
        // Add the job to the array of jobs.
        $this->jobs[] = $this->serializeJob($job);
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
     * Runs the jobs in the queue.
     *
     * This method will iterate over the jobs in the queue and execute them if
     * their scheduled time is in the past. If the job is repeated, it will be
     * rescheduled for the next time. If the job is not repeated, it will be
     * removed from the queue.
     *
     * @return void
     */
    public function run(): void
    {
        if (empty($this->getJobs())) {
            return;
        }

        $now = new DateTime();

        foreach ($this->getJobs() as $key => $serializedJob) {

            $job = $this->unserializeJob($serializedJob);

            // If the job is scheduled for the past, execute it.
            if ($job->getScheduledTime() <= $now) {
                // Otherwise, remove it from the queue.
                unset($this->jobs[$key]);

                $this->saveJobs(); // Save the jobs to the queue file.

                $job->handle(); // Execute the job.

                // If the job is repeated, reschedule it for the next time.
                if ($job->isRepeated()) {
                    $job->schedule(new DateTime($job->getRepeat()));
                    $this->addJob($job);
                }
            }
        }
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
     * Job objects. The data from the file is decoded from JSON and the closure
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
     * Serializes a Job object for storage.
     *
     * This method converts a Job object into an associative array that can be
     * easily stored in a JSON file. The closure is serialized using a 
     * SerializableClosure, and the event listeners are also serialized. The
     * scheduled time is formatted as an ISO 8601 string.
     *
     * @param Job $job
     *   The job to be serialized.
     *
     * @return array
     *   An associative array representation of the job.
     */
    private function serializeJob(Job $job): array
    {
        return [
            'closure' => serialize(new SerializableClosure($job->getClosure())), // Serialize the closure and save it.
            'scheduledTime' => $job->getScheduledTime()->format('c'), // Save the scheduled time as string.
            'repeat' => $job->getRepeat(), // Save the repeat as it is.
            'priority' => $job->getPriority(), // Save the priority as it is.
            'eventListeners' => collect(
                $job->getEventDispatcher()->getListeners()
            )
                ->mapK(fn($closures, $eventName) => [
                    $eventName => array_map(
                        fn($closure) => serialize(new SerializableClosure($closure)),
                        $closures
                    )
                ])
                ->all(), // Save the events as it is.
        ];
    }

    /**
     * Unserializes a Job object from storage.
     *
     * This method converts an associative array into a Job object. The closure
     * is unserialized using a SerializableClosure, and the event listeners are
     * also unserialized. The scheduled time is set from the string representation
     * of an ISO 8601 date.
     *
     * @param array $job
     *   An associative array representation of the job.
     *
     * @return Job
     *   A Job object.
     */
    private function unserializeJob(array $job): Job
    {
        return new Job(
            unserialize($job['closure'])->getClosure(), // Unserialize the closure and get the closure.
            new DateTime($job['scheduledTime']), // Set the scheduled time using the ISO 8601 string.
            $job['repeat'], // Set the repeat as it is.
            new EventDispatcher(
                collect($job['eventListeners'])
                    ->mapK(fn($closures, $eventName) => [
                        $eventName => array_map(
                            fn($closure) => unserialize($closure)->getClosure(), // Unserialize the closure and get the closure.
                            $closures
                        )
                    ])
                    ->all() // Set the event listeners as they are.
            ),
            $job['priority'] // Set the priority as it is.
        );
    }

    /**
     * Saves the jobs to the queue file.
     *
     * This method will take the current array of jobs in the queue and save them
     * to the queue file. The jobs are converted to an array of data that can be
     * saved to JSON. The closure is converted to a string by var_exporting it.
     * The scheduled time and repeat are saved as is.
     *
     * @return void
     */
    private function saveJobs(): void
    {
        // Convert the jobs to an array of data that can be saved to JSON.
        $jobs = collect($this->getJobs())
            ->multiSort('priority', true);

        // Save the jobs to the queue file.
        $isSaved = file_put_contents($this->storageFile, $jobs->toJson(), LOCK_EX);

        if ($isSaved === false) {
            throw new FailedToSaveJobsException('Failed to save jobs.');
        }

        $this->isChanged = false; // Set the changed flag to false.
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
        if ($this->isChanged) {
            $this->saveJobs();
        }
    }
}
