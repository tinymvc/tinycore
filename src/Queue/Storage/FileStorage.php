<?php

namespace Spark\Queue\Storage;

use Generator;
use JsonException;
use RuntimeException;
use Spark\Carbon;
use Spark\Queue\Contracts\JobContract;
use Spark\Queue\Contracts\QueueStorageContract;
use Spark\Queue\Exceptions\FailedToLoadJobsException;
use Spark\Queue\Exceptions\FailedToSaveJobsException;
use Throwable;
use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function basename;
use function bin2hex;
use function chmod;
use function clearstatcache;
use function ctype_digit;
use function dirname;
use function explode;
use function fclose;
use function fflush;
use function file_get_contents;
use function filemtime;
use function flock;
use function fopen;
use function function_exists;
use function fsync;
use function fwrite;
use function get_class;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function json_decode;
use function json_encode;
use function max;
use function md5;
use function microtime;
use function min;
use function mkdir;
use function octdec;
use function preg_match;
use function random_bytes;
use function rename;
use function rtrim;
use function scandir;
use function sha1;
use function sprintf;
use function str_ends_with;
use function strlen;
use function substr;
use function time;
use function trim;
use function unlink;
use function usleep;
use function usort;

/**
 * File based queue storage.
 *
 * Layout (a job's state is the directory it lives in, its file name sorts by due time):
 *
 *   <path>/jobs/pending/<md5(queue)>/<scheduled_ts:12>-<id:12>.job   waiting (or delayed) jobs
 *   <path>/jobs/active/<md5(queue)>/...                              reserved / processing jobs
 *   <path>/jobs/failed/<md5(queue)>/...                              permanently failed jobs
 *   <path>/fp/<sha1>                                                 fingerprint -> job id (pushOnce)
 *   <path>/corrupt/                                                  unreadable job files (quarantine)
 *   <path>/.counter                                                  last allocated job id
 *   <path>/.guard                                                    flock() target
 *
 * Stability rules:
 *  - Every operation that changes state runs under one exclusive flock() on `.guard`; read-only
 *    inspection takes a shared lock. flock() uses LOCK_NB inside a bounded retry loop, so a stuck
 *    process cannot block the others forever. The guard file is never replaced or deleted.
 *  - Files are written to a unique temp file and rename()d into place, so a reader never sees a
 *    partial job. A state change is "rewrite content atomically, then rename into the target
 *    directory", ordered so that a crash can at worst re-run a job (at-least-once), never lose one.
 *  - Because the lock is global, two workers can never claim the same job and pushOnce() is
 *    duplicate-free. Worker code never runs while the guard is held.
 *  - Job ids come from a counter that is written before the job file, so a crash never reuses an id.
 *
 * Supported config: path, file_mode (0664), dir_mode (0775), fsync (false), guard_timeout (5.0 s).
 *
 * NOTE: flock() needs a local filesystem (no NFS/SMB). Every claim lists the pending directory of
 * the queue, so this driver is meant for small and medium queues; use the database or Redis driver
 * for very large backlogs.
 */
class FileStorage implements QueueStorageContract
{
    use SerializesJobs;

    private const FORMAT = 'Y-m-d H:i:s';

    private const STATES = ['pending', 'active', 'failed'];

    private const JOB_EXT = '.job';

    private const TMP_PREFIX = '.tmp-';

    private string $root;

    private int $fileMode;

    private int $dirMode;

    private bool $fsync;

    private float $guardTimeout;

    /** @var array<int, string> Last known path per job id (avoids directory scans on the hot path). */
    private array $paths = [];

    public function __construct(private readonly array $config = [])
    {
        $path = trim((string) ($config['path'] ?? ''));
        if ($path === '') {
            $path = (string) storage_dir('queue/files');
        }

        $this->root = rtrim($path, '/\\');
        $this->fileMode = $this->mode($config['file_mode'] ?? 0664);
        $this->dirMode = $this->mode($config['dir_mode'] ?? 0775);
        $this->fsync = (bool) ($config['fsync'] ?? false);
        $this->guardTimeout = max(0.05, (float) ($config['guard_timeout'] ?? 5.0));
    }

    /**
     * The file driver has no PDO/Redis connection.
     */
    public function getConnection(): \Redis|\PDO
    {
        throw new RuntimeException('The file queue driver does not use a database or Redis connection.');
    }

    /* ---------------------------------------------------------------------
     | Producing
     | ------------------------------------------------------------------ */

    public function push(JobContract $job, string $queue = 'default'): void
    {
        try {
            $payload = $this->encodePayload($job);
            $this->guarded(fn() => $this->insert($job, $queue, $payload));
        } catch (RuntimeException | JsonException $e) {
            throw new FailedToSaveJobsException('Failed to add job to the queue: ' . $e->getMessage(), previous: $e);
        }
    }

    public function pushOnce(JobContract $job, string $queue = 'default'): void
    {
        try {
            $payload = $this->encodePayload($job);
            $repeat = $job->isRepeated() ? $job->getRepeat() : null;
            $fingerprint = $this->fingerprint($queue, $payload, $repeat);

            $this->guarded(function () use ($job, $queue, $payload, $fingerprint) {
                $existing = $this->readFingerprint($fingerprint);

                // A fingerprint of a job that no longer exists is stale and ignored.
                if ($existing !== null && $this->locate($existing) !== null) {
                    return;
                }

                // Ordinary push() permits duplicates; removing the indexed one may leave others.
                foreach ($this->eachRow(self::STATES, [$queue]) as [, , $row]) {
                    if ($row !== null && $this->fingerprint($row['queue'], $row['payload'], $row['repeat'] ?? null) === $fingerprint) {
                        $this->writeFingerprint($fingerprint, (int) $row['id']);

                        return;
                    }
                }

                $this->insert($job, $queue, $payload);
            });
        } catch (RuntimeException | JsonException $e) {
            throw new FailedToSaveJobsException('Failed to add job to the queue: ' . $e->getMessage(), previous: $e);
        }
    }

    /* ---------------------------------------------------------------------
     | Clearing / removing
     | ------------------------------------------------------------------ */

    public function clearAllJobs(): void
    {
        $this->guarded(function () {
            foreach ($this->eachFile(self::STATES, null) as [, $path]) {
                $this->discard($path, $this->readRow($path));
            }

            foreach ($this->listDir($this->root . DIRECTORY_SEPARATOR . 'fp') as $file) {
                @unlink($this->root . DIRECTORY_SEPARATOR . 'fp' . DIRECTORY_SEPARATOR . $file);
            }

            $this->paths = [];
        });
    }

    public function clearRepeatedJobs(): void
    {
        $this->guarded(function () {
            foreach ($this->eachRow(self::STATES, null) as [, $path, $row]) {
                if ($row !== null && (string) ($row['repeat'] ?? '') !== '') {
                    $this->discard($path, $row);
                }
            }
        });
    }

    public function clearFailedJobs(): void
    {
        $this->guarded(function () {
            foreach ($this->eachRow(['failed'], null) as [, $path, $row]) {
                $this->discard($path, $row);
            }
        });
    }

    public function removeJobById(int $id): bool
    {
        return (bool) $this->guarded(function () use ($id) {
            $path = $this->locate($id);

            if ($path === null) {
                return false;
            }

            $this->discard($path, $this->readRow($path));

            return true;
        });
    }

    public function removeQueue(string $name): bool
    {
        return (bool) $this->guarded(function () use ($name) {
            $removed = false;

            foreach ($this->eachRow(self::STATES, [$name]) as [, $path, $row]) {
                $this->discard($path, $row);
                $removed = true;
            }

            return $removed;
        });
    }

    /* ---------------------------------------------------------------------
     | Consuming
     | ------------------------------------------------------------------ */

    public function getNextJob(array|string $queue = 'default'): false|JobContract
    {
        $names = $this->toList($queue);
        if ($names === []) {
            return false;
        }

        return $this->guarded(function () use ($names) {
            $now = time();

            // The loop only repeats when a head-of-queue file turned out to be unreadable.
            for ($attempt = 0; $attempt < 100; $attempt++) {
                $best = null;

                foreach ($names as $name) {
                    $dir = $this->dir('pending', $name);

                    // Names sort by (due time, id), so the first due file is this queue's best.
                    foreach ($this->listJobFiles($dir) as $file) {
                        $key = $this->parseName($file);

                        if ($key === null) {
                            continue;
                        }

                        if ($key[0] > $now) {
                            break;
                        }

                        if ($best === null || $key < $best['key']) {
                            $best = ['key' => $key, 'path' => $dir . DIRECTORY_SEPARATOR . $file];
                        }

                        break;
                    }
                }

                if ($best === null) {
                    return false;
                }

                $row = $this->readRow($best['path']);
                if ($row === null) {
                    $this->quarantine($best['path']);
                    continue;
                }

                $row['status'] = 'reserved';
                $row['reserved_ts'] = $now;
                $row['reserved_at'] = now()->format(self::FORMAT);

                // Content first, then the atomic move: a crash in between leaves a normal pending job.
                $path = $this->transition($best['path'], $row, 'active');

                return $this->unserializeJob($this->jobRow('active', $row));
            }

            return false;
        });
    }

    public function updateJobStatus(int $jobId, string $status, int $attempts): void
    {
        $this->guarded(function () use ($jobId, $status, $attempts) {
            $path = $this->locate($jobId);
            $row = $path !== null ? $this->readRow($path) : null;

            if ($path === null || $row === null) {
                return;
            }

            $row['status'] = $status;
            $row['attempts'] = max(0, $attempts);
            $row['reserved_ts'] = null;
            $row['reserved_at'] = null;

            if (in_array($status, ['reserved', 'processing'], true)) {
                $row['reserved_ts'] = time();
                $row['reserved_at'] = now()->format(self::FORMAT);
                $target = 'active';
            } else {
                $target = match ($status) {
                    'pending' => 'pending',
                    'failed' => 'failed',
                    default => $this->stateOf($path),
                };
            }

            $this->transition($path, $row, $target);
        });
    }

    public function rescheduleJob(int $jobId, Carbon $nextRun): void
    {
        $this->reschedule($jobId, $nextRun, 0);
    }

    public function retryJob(int $jobId, Carbon $retryTime, int $attempts): void
    {
        $this->reschedule($jobId, $retryTime, $attempts);
    }

    public function markJobAsFailed(int $jobId, Throwable $exception, int $attempts): void
    {
        $stackTrace = $exception->getPrevious()?->getTraceAsString() ?? $exception->getTraceAsString();
        $text = sprintf("%s: %s\nStack trace:\n%s", get_class($exception), $exception->getMessage(), $stackTrace);

        $this->guarded(function () use ($jobId, $attempts, $text) {
            $path = $this->locate($jobId);
            $row = $path !== null ? $this->readRow($path) : null;

            if ($path === null || $row === null) {
                return;
            }

            $row['status'] = 'failed';
            $row['attempts'] = $attempts;
            $row['reserved_ts'] = null;
            $row['reserved_at'] = null;
            $row['failed_ts'] = time();
            $row['failed_at'] = now()->format(self::FORMAT);
            $row['exception'] = $text;

            $this->transition($path, $row, 'failed');
        });
    }

    public function recoverStaleJobs(int $timeout = 3600): int
    {
        return (int) $this->guarded(function () use ($timeout) {
            $staleBefore = time() - $timeout;
            $recovered = 0;

            foreach ($this->eachRow(['active'], null) as [, $path, $row]) {
                if ($row === null) {
                    $this->quarantine($path);
                    continue;
                }

                // A crash between claim steps can leave no reserved_ts: fall back to the file's mtime.
                $reservedAt = isset($row['reserved_ts']) ? (int) $row['reserved_ts'] : (int) @filemtime($path);

                if ($reservedAt >= $staleBefore) {
                    continue;
                }

                $row['status'] = 'pending';
                $row['reserved_ts'] = null;
                $row['reserved_at'] = null;

                $this->transition($path, $row, 'pending');
                $recovered++;
            }

            return $recovered;
        });
    }

    /* ---------------------------------------------------------------------
     | Inspecting
     | ------------------------------------------------------------------ */

    public function getJobs(
        array|string|null $queue = null,
        array|string|null $status = null,
        int $from = 0,
        int $to = 500,
    ): array {
        try {
            $queues = $this->toList($queue);
            $statuses = $this->toList($status);

            $states = self::STATES;
            if ($statuses !== []) {
                $states = array_values(array_filter(self::STATES, static fn(string $state) => match ($state) {
                    'pending' => in_array('pending', $statuses, true),
                    'failed' => in_array('failed', $statuses, true),
                    default => in_array('reserved', $statuses, true) || in_array('processing', $statuses, true),
                }));
            }

            $rows = $this->guarded(function () use ($states, $queues, $statuses) {
                $collected = [];

                foreach ($this->eachRow($states, $queues !== [] ? $queues : null) as [$state, , $row]) {
                    if ($row === null) {
                        continue;
                    }

                    $jobRow = $this->jobRow($state, $row);

                    if ($statuses !== [] && !in_array($jobRow['status'], $statuses, true)) {
                        continue;
                    }

                    $collected[] = [(int) ($row['scheduled_ts'] ?? 0), (int) $row['id'], $jobRow];
                }

                return $collected;
            }, true);

            usort($rows, static fn(array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

            return array_map(
                fn(array $entry) => $this->unserializeJob($entry[2]),
                array_slice($rows, max(0, $from), max(0, $to))
            );
        } catch (RuntimeException $e) {
            throw new FailedToLoadJobsException('Failed to load jobs from the queue: ' . $e->getMessage(), previous: $e);
        }
    }

    public function getFailedJobs(int $from = 0, int $to = 500): array
    {
        try {
            $rows = $this->guarded(function () {
                $collected = [];

                foreach ($this->eachRow(['failed'], null) as [, , $row]) {
                    if ($row !== null) {
                        $collected[] = [(int) ($row['failed_ts'] ?? 0), (int) $row['id'], $this->jobRow('failed', $row)];
                    }
                }

                return $collected;
            }, true);

            // Newest failure first.
            usort($rows, static fn(array $a, array $b) => [$b[0], $b[1]] <=> [$a[0], $a[1]]);

            return array_map(
                fn(array $entry) => $this->unserializeJob($entry[2]),
                array_slice($rows, max(0, $from), max(0, $to))
            );
        } catch (RuntimeException $e) {
            throw new FailedToLoadJobsException('Failed to load failed jobs from the queue: ' . $e->getMessage(), previous: $e);
        }
    }

    public function retryFailedJobs(): void
    {
        $this->guarded(function () {
            foreach ($this->eachRow(['failed'], null) as [, $path, $row]) {
                if ($row === null) {
                    $this->quarantine($path);
                    continue;
                }

                $row['status'] = 'pending';
                $row['attempts'] = 0;
                $row['reserved_ts'] = null;
                $row['reserved_at'] = null;
                $row['failed_ts'] = null;
                $row['failed_at'] = null;
                $row['exception'] = null;

                $this->transition($path, $row, 'pending');
            }
        });
    }

    /* ---------------------------------------------------------------------
     | Internals: state changes (call only while the guard is held)
     | ------------------------------------------------------------------ */

    private function insert(JobContract $job, string $queue, string $payload): int
    {
        $id = $this->nextId();
        $scheduled = $job->getScheduledTime();
        $repeat = $job->isRepeated() ? $job->getRepeat() : null;

        $row = [
            'id' => $id,
            'queue' => $queue,
            'payload' => $payload,
            'scheduled_time' => $scheduled->utc()->format(self::FORMAT),
            'scheduled_ts' => $scheduled->getTimestamp(),
            'created_at' => now()->format(self::FORMAT),
            'repeat' => $repeat,
            'status' => 'pending',
            'attempts' => 0,
            'reserved_ts' => null,
            'reserved_at' => null,
            'failed_ts' => null,
            'failed_at' => null,
            'exception' => null,
        ];

        $path = $this->dir('pending', $queue) . DIRECTORY_SEPARATOR . $this->fileName($row['scheduled_ts'], $id);

        $this->writeRow($path, $row);
        $this->paths[$id] = $path;
        $this->writeFingerprint($this->fingerprint($queue, $payload, $repeat), $id);

        return $id;
    }

    private function reschedule(int $jobId, Carbon $time, int $attempts): void
    {
        $this->guarded(function () use ($jobId, $time, $attempts) {
            $path = $this->locate($jobId);
            $row = $path !== null ? $this->readRow($path) : null;

            if ($path === null || $row === null) {
                return;
            }

            $row['status'] = 'pending';
            $row['attempts'] = $attempts;
            $row['scheduled_ts'] = $time->getTimestamp();
            $row['scheduled_time'] = $time->utc()->format(self::FORMAT);
            $row['reserved_ts'] = null;
            $row['reserved_at'] = null;

            $this->transition($path, $row, 'pending');
        });
    }

    /**
     * Rewrite the job content in place (atomic), then move it to the target state directory.
     * The directory is the source of truth for the state.
     */
    private function transition(string $path, array $row, string $state): string
    {
        $this->writeRow($path, $row);

        $target = $this->dir($state, (string) $row['queue']) . DIRECTORY_SEPARATOR
            . $this->fileName((int) $row['scheduled_ts'], (int) $row['id']);

        if ($target !== $path) {
            $this->ensureDirectory(dirname($target));

            if (!$this->moveFile($path, $target)) {
                throw new RuntimeException("Unable to move job file to [{$target}].");
            }
        }

        $this->paths[(int) $row['id']] = $target;

        return $target;
    }

    private function discard(string $path, ?array $row): void
    {
        if (is_file($path) && !@unlink($path)) {
            throw new RuntimeException("Unable to remove job file [{$path}].");
        }

        if ($row === null) {
            return;
        }

        unset($this->paths[(int) $row['id']]);

        $fingerprint = $this->fingerprint(
            (string) $row['queue'],
            (string) $row['payload'],
            (string) ($row['repeat'] ?? '') !== '' ? (string) $row['repeat'] : null
        );

        // Only drop the fingerprint if it still points at this job.
        if ($this->readFingerprint($fingerprint) === (int) $row['id']) {
            @unlink($this->root . DIRECTORY_SEPARATOR . 'fp' . DIRECTORY_SEPARATOR . $fingerprint);
        }
    }

    private function quarantine(string $path): void
    {
        $dir = $this->root . DIRECTORY_SEPARATOR . 'corrupt';
        $this->ensureDirectory($dir);

        if (!$this->moveFile($path, $dir . DIRECTORY_SEPARATOR . bin2hex(random_bytes(4)) . '-' . basename($path))) {
            @unlink($path);
        }
    }

    private function nextId(): int
    {
        $counter = $this->root . DIRECTORY_SEPARATOR . '.counter';
        $raw = @file_get_contents($counter);

        $current = is_string($raw) && ctype_digit(trim($raw)) ? (int) trim($raw) : $this->highestExistingId();
        $id = $current + 1;

        // Written before the job file: a crash wastes an id but can never reuse one.
        $this->writeAtomic($counter, (string) $id);

        return $id;
    }

    private function highestExistingId(): int
    {
        $highest = 0;

        foreach ($this->eachFile(self::STATES, null) as [, $path]) {
            $key = $this->parseName(basename($path));
            $key !== null && $highest = max($highest, $key[1]);
        }

        return $highest;
    }

    /* ---------------------------------------------------------------------
     | Internals: lookups
     | ------------------------------------------------------------------ */

    private function locate(int $id): ?string
    {
        $cached = $this->paths[$id] ?? null;
        if ($cached !== null && is_file($cached)) {
            return $cached;
        }

        unset($this->paths[$id]);
        $suffix = sprintf('-%012d%s', $id, self::JOB_EXT);

        foreach ($this->eachFile(['active', 'pending', 'failed'], null) as [, $path]) {
            if (str_ends_with($path, $suffix)) {
                return $this->paths[$id] = $path;
            }
        }

        return null;
    }

    /**
     * @param string[] $states
     * @param null|string[] $queues
     * @return Generator<int, array{0: string, 1: string}> [state, path]
     */
    private function eachFile(array $states, ?array $queues): Generator
    {
        foreach ($states as $state) {
            $base = $this->root . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . $state;
            $hashes = $queues === null ? $this->listDir($base) : array_map('md5', $queues);

            foreach ($hashes as $hash) {
                $dir = $base . DIRECTORY_SEPARATOR . $hash;

                foreach ($this->listJobFiles($dir) as $file) {
                    yield [$state, $dir . DIRECTORY_SEPARATOR . $file];
                }
            }
        }
    }

    /**
     * @param string[] $states
     * @param null|string[] $queues
     * @return Generator<int, array{0: string, 1: string, 2: null|array}> [state, path, row|null if unreadable]
     */
    private function eachRow(array $states, ?array $queues): Generator
    {
        foreach ($this->eachFile($states, $queues) as [$state, $path]) {
            yield [$state, $path, $this->readRow($path)];
        }
    }

    /**
     * Map a stored row to the array shape SerializesJobs::unserializeJob() expects.
     *
     * @return array<string, mixed>
     */
    private function jobRow(string $state, array $row): array
    {
        $status = match ($state) {
            'pending' => 'pending',
            'failed' => 'failed',
            default => in_array($row['status'] ?? '', ['reserved', 'processing'], true) ? $row['status'] : 'reserved',
        };

        return [
            'id' => (int) $row['id'],
            'payload' => $row['payload'] ?? '{}',
            'queue' => $row['queue'] ?? 'default',
            'scheduled_time' => $row['scheduled_time'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'repeat' => (string) ($row['repeat'] ?? '') !== '' ? $row['repeat'] : null,
            'status' => $status,
            'attempts' => (int) ($row['attempts'] ?? 0),
            'reserved_at' => $row['reserved_at'] ?? null,
            'failed_at' => $row['failed_at'] ?? null,
            'exception' => $row['exception'] ?? null,
            'failed_job_id' => $state === 'failed' ? (int) $row['id'] : null,
        ];
    }

    /* ---------------------------------------------------------------------
     | Internals: fingerprints (pushOnce)
     | ------------------------------------------------------------------ */

    private function fingerprint(string $queue, string $payload, ?string $repeat): string
    {
        return sha1($queue . "\0" . $payload . "\0" . ($repeat ?? ''));
    }

    private function readFingerprint(string $fingerprint): ?int
    {
        $raw = @file_get_contents($this->root . DIRECTORY_SEPARATOR . 'fp' . DIRECTORY_SEPARATOR . $fingerprint);

        return is_string($raw) && ctype_digit(trim($raw)) ? (int) trim($raw) : null;
    }

    private function writeFingerprint(string $fingerprint, int $id): void
    {
        $this->writeAtomic($this->root . DIRECTORY_SEPARATOR . 'fp' . DIRECTORY_SEPARATOR . $fingerprint, (string) $id);
    }

    /* ---------------------------------------------------------------------
     | Internals: locking and file primitives
     | ------------------------------------------------------------------ */

    /**
     * Run a callback under the global guard (exclusive, or shared for read-only work).
     * Never call this re-entrantly and never run job code inside the callback.
     */
    private function guarded(callable $callback, bool $shared = false): mixed
    {
        $this->ensureDirectory($this->root);

        $handle = @fopen($this->root . DIRECTORY_SEPARATOR . '.guard', 'c+');
        if ($handle === false) {
            throw new RuntimeException("Unable to open the queue guard file in [{$this->root}].");
        }

        try {
            $operation = ($shared ? LOCK_SH : LOCK_EX) | LOCK_NB;
            $deadline = microtime(true) + $this->guardTimeout;
            $sleep = 200;

            while (!flock($handle, $operation, $wouldBlock)) {
                if (!$wouldBlock) {
                    throw new RuntimeException('Unable to lock the queue guard file.');
                }

                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Timed out waiting for the queue guard.');
                }

                usleep($sleep);
                $sleep = min($sleep * 2, 5000);
            }

            try {
                return $callback();
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    private function writeRow(string $path, array $row): void
    {
        $this->ensureDirectory(dirname($path));

        $this->writeAtomic($path, json_encode(
            $row,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        ));
    }

    private function readRow(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $row = json_decode($raw, true);

        if (!is_array($row) || !isset($row['id'], $row['queue'], $row['payload']) || !is_string($row['payload'])) {
            return null;
        }

        return $row;
    }

    private function writeAtomic(string $path, string $contents): void
    {
        $dir = dirname($path);
        $this->ensureDirectory($dir);

        $tmp = $dir . DIRECTORY_SEPARATOR . self::TMP_PREFIX . bin2hex(random_bytes(8));
        $handle = @fopen($tmp, 'xb');

        if ($handle === false) {
            throw new RuntimeException("Unable to create a temporary file in [{$dir}].");
        }

        try {
            $length = strlen($contents);
            $written = 0;

            while ($written < $length) {
                $bytes = @fwrite($handle, substr($contents, $written));

                if ($bytes === false || $bytes === 0) {
                    throw new RuntimeException("Failed writing [{$path}] (disk full?).");
                }

                $written += $bytes;
            }

            if (!fflush($handle) || ($this->fsync && function_exists('fsync') && !fsync($handle))) {
                throw new RuntimeException('Unable to flush storage file.');
            }
        } catch (Throwable $e) {
            fclose($handle);
            @unlink($tmp);
            throw $e;
        }

        fclose($handle);
        @chmod($tmp, $this->fileMode);

        if (!$this->moveFile($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Unable to move file into place [{$path}].");
        }
    }

    /**
     * rename() is atomic on POSIX. On Windows it can fail briefly while another process has the
     * target open, so retry a few times.
     */
    private function moveFile(string $from, string $to): bool
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            if (@rename($from, $to)) {
                return true;
            }

            usleep(10000 * $attempt);
            clearstatcache(true, $to);
        }

        return false;
    }

    private function ensureDirectory(string $directory): void
    {
        if ($directory === '' || is_dir($directory)) {
            return;
        }

        if (!@mkdir($directory, $this->dirMode, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create directory [{$directory}].");
        }
    }

    private function dir(string $state, string $queue): string
    {
        return $this->root . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . $state . DIRECTORY_SEPARATOR . md5($queue);
    }

    private function stateOf(string $path): string
    {
        return basename(dirname(dirname($path)));
    }

    private function fileName(int $scheduledTs, int $id): string
    {
        return sprintf('%012d-%012d%s', max(0, $scheduledTs), $id, self::JOB_EXT);
    }

    /**
     * @return null|array{0: int, 1: int} [scheduled timestamp, id]
     */
    private function parseName(string $file): ?array
    {
        if (preg_match('/^(\d{12})-(\d{12})\.job$/', $file, $m) !== 1) {
            return null;
        }

        return [(int) $m[1], (int) $m[2]];
    }

    /**
     * @return string[] Job file names of one directory, sorted ascending (= by due time, then id).
     */
    private function listJobFiles(string $dir): array
    {
        return array_values(array_filter(
            $this->listDir($dir),
            static fn(string $file) => str_ends_with($file, self::JOB_EXT)
        ));
    }

    /**
     * @return string[]
     */
    private function listDir(string $dir): array
    {
        $items = is_dir($dir) ? @scandir($dir) : false;

        if ($items === false) {
            return [];
        }

        return array_values(array_filter($items, static fn(string $item) => $item !== '.' && $item !== '..'));
    }

    /**
     * @throws JsonException
     */
    private function encodePayload(JobContract $job): string
    {
        $payload = $this->serializeJob($job);

        return json_encode(
            ['callback' => $payload['callback'], 'parameters' => $payload['parameters']],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    /**
     * @return string[]
     */
    private function toList(array|string|null $values): array
    {
        if ($values === null || $values === '' || $values === []) {
            return [];
        }

        if (is_string($values)) {
            $values = explode(',', $values);
        }

        return array_values(array_filter(array_map(static fn($v) => trim((string) $v), $values), static fn($v) => $v !== ''));
    }

    private function mode(mixed $mode): int
    {
        if (is_string($mode)) {
            return (int) octdec($mode);
        }

        return (int) $mode;
    }
}