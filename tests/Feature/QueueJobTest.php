<?php

require_once dirname(__DIR__) . '/Support/FrameworkTestCase.php';

use Spark\Queue\Job;

final class QueueJobTest extends FrameworkTestCase
{
    public function test_retry_policy_uses_job_values_and_last_backoff_for_later_attempts(): void
    {
        $job = new Job(QueuePolicyFixture::class);
        $this->assertSame(3, $job->getTries(1));
        $this->assertSame(2, $job->getBackoff(0, 1));
        $this->assertSame(5, $job->getBackoff(0, 2));
        $this->assertSame(5, $job->getBackoff(0, 99));
    }

    public function test_function_job_retry_defaults_are_clamped(): void
    {
        $job = new Job('strlen', ['value']);
        $this->assertSame(1, $job->getTries(0));
        $this->assertSame(0, $job->getBackoff(-10, 1));
        $this->assertSame(7, $job->getBackoff(7, 1));
    }

    public function test_failure_hook_receives_original_exception(): void
    {
        $fixture = new QueuePolicyFixture();
        $job = new Job([$fixture, 'handle']);
        $error = new RuntimeException('job failed');
        $job->failed($error);
        $this->assertSame($error, $fixture->failure);
    }

    public function test_repeat_alias_and_copy_schedule_are_independent(): void
    {
        $job = (new Job('strlen', ['value'], '2030-01-01 00:00:00'))->repeat('DAILY');
        $copy = $job->copy();
        $copy->schedule('2031-01-01 00:00:00');
        $this->assertSame('1 day', $job->getRepeat());
        $this->assertTrue($job->isRepeated());
        $this->assertSame('2030-01-01', $job->getScheduledTime()->format('Y-m-d'));
        $this->assertSame('2031-01-01', $copy->getScheduledTime()->format('Y-m-d'));
    }

    public function test_unresolvable_job_reports_failure(): void
    {
        $this->assertThrows(\Spark\Queue\Exceptions\FailedToResolveJobError::class, fn() => (new Job('NoSuchQueueCallback'))->handle());
    }
}

class QueuePolicyFixture
{
    public int $tries = 3;
    public array $backoff = [2, 5];
    public ?Throwable $failure = null;
    public function handle(): void
    {
    }
    public function failed(Throwable $exception): void
    {
        $this->failure = $exception;
    }
}
