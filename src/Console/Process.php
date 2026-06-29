<?php

namespace Spark\Console;

use Spark\Console\Exceptions\ProcessFailedException;
use Spark\Console\Exceptions\ProcessTimedOutException;
use Spark\Support\Traits\Conditionable;
use Spark\Support\Traits\Macroable;
use function is_array;
use function is_resource;

/**
 * A simple process execution utility for running shell commands with real-time output capture,
 * environment variable support, timeouts, and TTY mode.
 *
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Process implements \Stringable
{
    use Macroable, Conditionable;

    // ── Configuration ───────────────────────────────────
    protected string|array $command;
    protected ?string $cwd = null;
    protected ?int $timeout = 60;
    protected array $env = [];
    protected ?string $input = null;
    protected bool $tty = false;

    protected ?int $exitCode = null;
    protected string $output = '';
    protected string $errorOutput = '';
    protected float $executionTime = 0.0;

    public function __construct(string|array $command, ?string $cwd = null, ?int $timeout = 60)
    {
        $this->command = $command;
        $this->cwd = $cwd;
        $this->timeout = $timeout;
    }

    // ── Factories ─────────────────────────────────────────────────────────────

    public static function command(string|array $command): static
    {
        return new static($command);
    }

    public static function run(string|array $command, ?string $cwd = null, ?int $timeout = 60): static
    {
        return static::command($command)->path($cwd)->timeout($timeout)->execute();
    }

    // ── Builder ───────────────────────────────────────────────────────────────

    public function path(?string $cwd): static
    {
        $this->cwd = $cwd;
        return $this;
    }

    public function timeout(?int $seconds): static
    {
        $this->timeout = $seconds;
        return $this;
    }

    public function forever(): static
    {
        $this->timeout = null;
        return $this;
    }

    public function env(array $env): static
    {
        $this->env = [...$this->env, ...$env];
        return $this;
    }

    public function input(string $data): static
    {
        $this->input = $data;
        return $this;
    }

    public function tty(bool $tty = true): static
    {
        $this->tty = $tty;
        return $this;
    }

    // ── Execution ─────────────────────────────────────────────────────────────

    /**
     * @throws ProcessFailedException
     * @throws ProcessTimedOutException
     */
    public function execute(): static
    {
        return $this->tty ? $this->executeTty() : $this->executePiped();
    }

    private function executePiped(): static
    {
        $start = microtime(true);
        $env = empty($this->env) ? null : [...$_ENV, ...$this->env];
        $options = $this->procOptions();
        $pipes = [];
        $process = null;
        $timedOut = false;

        $process = proc_open(
            $this->command,
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $this->cwd,
            $env,
            $options
        );

        if (!is_resource($process)) {
            throw new ProcessFailedException("Failed to start: {$this->stringifyCommand()}");
        }

        if ($this->input !== null) {
            @fwrite($pipes[0], $this->input);
        }
        fclose($pipes[0]);

        @stream_set_blocking($pipes[1], false);
        @stream_set_blocking($pipes[2], false);
        @stream_set_read_buffer($pipes[1], 0);
        @stream_set_read_buffer($pipes[2], 0);

        $out = '';
        $err = '';
        try {
            while (true) {
                if ($this->timeout !== null && (microtime(true) - $start) > $this->timeout) {
                    $this->terminate($process);
                    $timedOut = true;
                    break;
                }

                foreach ([$pipes[1], $pipes[2]] as $stream) {
                    $chunk = @fread($stream, 65_536);
                    if ($chunk !== '' && $chunk !== false) {
                        $stream === $pipes[1] ? $out .= $chunk : $err .= $chunk;
                    }
                }

                $status = proc_get_status($process);
                if (($status['running'] ?? false) === false && feof($pipes[1]) && feof($pipes[2])) {
                    break;
                }

                usleep(20_000);
            }

            // Final drain
            $out .= (string) stream_get_contents($pipes[1]);
            $err .= (string) stream_get_contents($pipes[2]);
        } finally {
            $this->closePipe($pipes[0] ?? null);
            $this->closePipe($pipes[1] ?? null);
            $this->closePipe($pipes[2] ?? null);

            if (is_resource($process)) {
                $status = proc_get_status($process);
                if ($status !== false && ($status['running'] ?? false)) {
                    $this->terminate($process);
                }

                $this->exitCode = proc_close($process);
            }

            $this->executionTime = microtime(true) - $start;
        }

        $this->output = $out;
        $this->errorOutput = $err;

        if ($timedOut) {
            throw new ProcessTimedOutException(
                "Process timed out after {$this->timeout}s: {$this->stringifyCommand()}"
            );
        }

        return $this;
    }

    private function executeTty(): static
    {
        $start = microtime(true);
        $env = empty($this->env) ? null : [...$_ENV, ...$this->env];
        $pipes = [];
        $options = $this->procOptions();
        $process = null;

        $process = proc_open(
            $this->command,
            [STDIN, STDOUT, STDERR],
            $pipes,
            $this->cwd,
            $env,
            $options
        );

        if (!is_resource($process)) {
            throw new ProcessFailedException("Failed to start: {$this->stringifyCommand()}");
        }

        $timedOut = false;
        try {
            while (true) {
                $status = proc_get_status($process);
                if (($status['running'] ?? false) === false) {
                    break;
                }

                if ($this->timeout !== null && (microtime(true) - $start) > $this->timeout) {
                    $this->terminate($process);
                    $timedOut = true;
                    break;
                }

                usleep(10_000);
            }
        } finally {
            $this->closePipe($pipes[0] ?? null);
            $this->closePipe($pipes[1] ?? null);
            $this->closePipe($pipes[2] ?? null);

            if (is_resource($process)) {
                $status = proc_get_status($process);
                if ($status !== false && ($status['running'] ?? false)) {
                    $this->terminate($process);
                }

                $this->exitCode = proc_close($process);
            }

            $this->executionTime = microtime(true) - $start;
        }

        if ($timedOut) {
            throw new ProcessTimedOutException(
                "Process timed out after {$this->timeout}s: {$this->stringifyCommand()}"
            );
        }

        return $this;
    }

    private function terminate($process): void
    {
        $signalTerm = \defined('SIGTERM') ? \constant('SIGTERM') : 15;
        $signalKill = \defined('SIGKILL') ? \constant('SIGKILL') : 9;

        proc_terminate($process, $signalTerm);
        usleep(200_000);
        if (proc_get_status($process)['running']) {
            proc_terminate($process, $signalKill);
        }
    }

    private function closePipe(mixed $pipe): void
    {
        if (is_resource($pipe)) {
            @fclose($pipe);
        }
    }

    private function procOptions(): array
    {
        if (!windows_os()) {
            return [];
        }

        $options = [];

        if (!is_array($this->command)) {
            $options['bypass_shell'] = true;
        }

        return $options;
    }

    private function stringifyCommand(): string
    {
        if (is_array($this->command)) {
            return implode(' ', array_map('escapeshellarg', $this->command));
        }

        return $this->command;
    }

    // ── Assertions ────────────────────────────────────────────────────────────

    /** @throws ProcessFailedException */
    public function throw(): static
    {
        if ($this->failed()) {
            throw new ProcessFailedException(
                "Process failed (exit {$this->exitCode}): {$this->stringifyCommand()}"
                . ($this->output ? "\n\n[stdout]\n{$this->output}" : '')
                . ($this->errorOutput ? "\n\n[stderr]\n{$this->errorOutput}" : '')
            );
        }

        return $this;
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }

    public function failed(): bool
    {
        return !$this->successful();
    }

    public function output(): string
    {
        return trim($this->output);
    }

    public function errorOutput(): string
    {
        return trim($this->errorOutput);
    }

    public function exitCode(): ?int
    {
        return $this->exitCode;
    }

    public function executionTime(): float
    {
        return $this->executionTime;
    }

    public function getCommand(): string
    {
        return $this->stringifyCommand();
    }

    public function __toString(): string
    {
        return $this->output();
    }
}
