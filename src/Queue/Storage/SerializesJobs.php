<?php

namespace Spark\Queue\Storage;

use Spark\Carbon;
use Spark\Queue\Contracts\JobContract;
use Spark\Queue\Job;
use function is_array;

trait SerializesJobs
{
    protected function serializeJob(JobContract $job): array
    {
        return [
            'callback' => $job->getCallback(),
            'parameters' => $job->getParameters(),
            'scheduledTime' => (string) $job->getScheduledTime(),
            'repeat' => $job->getRepeat(),
            'metadata' => $job->getMetadata(),
        ];
    }

    protected function unserializeJob(array $job): JobContract
    {
        $payload = json_decode((string) ($job['payload'] ?? '{}'), true);
        if (!is_array($payload)) {
            $payload = [];
        }

        return new Job(
            callback: $payload['callback'] ?? null,
            parameters: $payload['parameters'] ?? [],
            scheduledTime: new Carbon($job['scheduled_time'] ?? now()),
            repeat: $job['repeat'] ?? null,
            metadata: [
                'id' => $job['id'] ?? null,
                'queue' => $job['queue'] ?? 'default',
                'attempts' => $job['attempts'] ?? 0,
                'created_at' => $job['created_at'] ?? null,
                'status' => $job['status'] ?? 'pending',
                'failed_job_id' => $job['failed_job_id'] ?? null,
                'failed_at' => $job['failed_at'] ?? null,
                'reserved_at' => $job['reserved_at'] ?? null,
                'exception' => $job['exception'] ?? null,
            ],
        );
    }
}
