<?php

namespace Spark\Console;

use Spark\Console\Exceptions\ProcessFailedException;
use Spark\Console\Exceptions\ProcessTimedOutException;
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
            fwrite($pipes[0], $this->input);
        }
        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        stream_set_read_buffer($pipes[1], 0);
        stream_set_read_buffer($pipes[2], 0);

        $out = '';
        $err = '';
        $timedOut = false;

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

            $running = proc_get_status($process)['running'];
            if (!$running && feof($pipes[1]) && feof($pipes[2])) {
                break;
            }

            usleep(20_000);
        }

        // Final drain
        $out .= stream_get_contents($pipes[1]);
        $err .= stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->exitCode = proc_close($process);
        $this->output = $out;
        $this->errorOutput = $err;
        $this->executionTime = microtime(true) - $start;

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

        while (proc_get_status($process)['running']) {
            if ($this->timeout !== null && (microtime(true) - $start) > $this->timeout) {
                $this->terminate($process);
                throw new ProcessTimedOutException(
                    "Process timed out after {$this->timeout}s: {$this->stringifyCommand()}"
                );
            }
            usleep(10_000);
        }

        $this->exitCode = proc_close($process);
        $this->executionTime = microtime(true) - $start;

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