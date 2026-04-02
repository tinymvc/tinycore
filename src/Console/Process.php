<?php

namespace Spark\Console;

use Spark\Console\Exceptions\ProcessFailedException;
use Spark\Console\Exceptions\ProcessTimedOutException;
use function is_array;
use function is_resource;

/**
 * A simple process execution class that allows you to run shell commands with various options.
 * It provides methods to set the working directory, timeout, environment variables, input, and TTY mode.
 * It also captures the output, error output, exit code, and execution time of the process.
 * 
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
class Process implements \Stringable
{
    /** @var string The command to execute */
    protected string $command;

    /** @var string|null The working directory for the process */
    protected ?string $workingDirectory = null;

    /** @var int|null The timeout for the process in seconds (null for no timeout) */
    protected ?int $timeout = 60;

    /** @var array The environment variables to set for the process */
    protected array $env = [];

    /** @var string|null The input to pass to the process */
    protected ?string $input = null;

    /** @var bool Whether to run the process in TTY mode */
    protected bool $tty = false;

    /** @var int|null The exit code of the process (null if not executed yet) */
    protected ?int $exitCode = null;

    /** @var string The standard output of the process */
    protected string $output = '';

    /** @var string The error output of the process */
    protected string $errorOutput = '';

    /** @var float The execution time of the process in seconds */
    protected float $executionTime = 0;

    /** 
     * Create a new Process instance.
     *
     * @param string $command The command to execute
     * @param string|null $workingDirectory The working directory for the process
     * @param int|null $timeout The timeout for the process in seconds (null for no timeout)
     */
    public function __construct(string $command, ?string $workingDirectory = null, ?int $timeout = 60)
    {
        $this->command = $command;
        $this->workingDirectory = $workingDirectory;
        $this->timeout = $timeout;
    }

    /** 
     * Run a command and return the Process instance.
     *
     * @param string|array $command The command to execute (string or array of arguments)
     * @param string|null $workingDirectory The working directory for the process
     * @param int|null $timeout The timeout for the process in seconds (null for no timeout)
     * @return static The Process instance after execution
     */
    public static function run(string|array $command, ?string $workingDirectory = null, ?int $timeout = 60): static
    {
        $command = is_array($command) ? implode(
            ' ',
            array_map('escapeshellarg', $command)
        ) : $command;

        $process = new static($command, $workingDirectory, $timeout);
        $process->execute();

        return $process;
    }

    /** 
     * Create a new Process instance with the given command.
     *
     * @param string|array $command The command to execute (string or array of arguments)
     * @return static The Process instance
     */
    public static function command(string|array $command): static
    {
        $command = is_array($command) ? implode(
            ' ',
            array_map('escapeshellarg', $command)
        ) : $command;

        return new static($command);
    }

    /** 
     * Set the working directory for the process.
     *
     * @param string $workingDirectory The working directory to set
     * @return static The Process instance
     */
    public function path(string $workingDirectory): static
    {
        $this->workingDirectory = $workingDirectory;
        return $this;
    }

    /** 
     * Set the timeout for the process.
     *
     * @param int $timeout The timeout in seconds
     * @return static The Process instance
     */
    public function timeout(int $timeout): static
    {
        $this->timeout = $timeout;
        return $this;
    }

    /** 
     * Set the process to run indefinitely (no timeout).
     *
     * @return static The Process instance
     */
    public function forever(): static
    {
        $this->timeout = null;
        return $this;
    }

    /** 
     * Set environment variables for the process.
     *
     * @param array $env An associative array of environment variables to set
     * @return static The Process instance
     */
    public function env(array $env): static
    {
        $this->env = [...$this->env, ...$env];
        return $this;
    }

    /** 
     * Set the input to pass to the process.
     *
     * @param string $input The input string to pass to the process
     * @return static The Process instance
     */
    public function input(string $input): static
    {
        $this->input = $input;
        return $this;
    }

    /** 
     * Set whether to run the process in TTY mode.
     *
     * @param bool $tty Whether to enable TTY mode (default: true)
     * @return static The Process instance
     */
    public function tty(bool $tty = true): static
    {
        $this->tty = $tty;
        return $this;
    }

    /** 
     * Execute the process and capture the output, error output, exit code, and execution time.
     *
     * @return static The Process instance after execution
     * @throws ProcessFailedException If the process fails to start
     * @throws ProcessTimedOutException If the process exceeds the specified timeout
     */
    public function execute(): static
    {
        $startTime = microtime(true);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = empty($this->env) ? null : [...$_ENV, ...$this->env];

        $process = proc_open(
            $this->command,
            $descriptors,
            $pipes,
            $this->workingDirectory,
            $env
        );

        if (!is_resource($process)) {
            throw new ProcessFailedException('Failed to start process.');
        }

        if ($this->input !== null) {
            fwrite($pipes[0], $this->input);
        }
        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $errorOutput = '';
        $timeoutReached = false;

        while (true) {
            $status = proc_get_status($process);

            if ($this->timeout !== null && (microtime(true) - $startTime) > $this->timeout) {
                proc_terminate($process);
                $timeoutReached = true;
                break;
            }

            $output .= stream_get_contents($pipes[1]);
            $errorOutput .= stream_get_contents($pipes[2]);

            if (!$status['running']) {
                break;
            }

            usleep(10000);
        }

        $output .= stream_get_contents($pipes[1]);
        $errorOutput .= stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->exitCode = proc_close($process);
        $this->output = $output;
        $this->errorOutput = $errorOutput;
        $this->executionTime = microtime(true) - $startTime;

        if ($timeoutReached) {
            throw new ProcessTimedOutException("Process exceeded timeout of {$this->timeout} seconds.");
        }

        return $this;
    }

    /** 
     * Throw an exception if the process failed.
     *
     * @return static The Process instance
     * @throws ProcessFailedException If the process failed (non-zero exit code)
     */
    public function throw(): static
    {
        if ($this->failed()) {
            throw new ProcessFailedException(
                "Process failed with exit code {$this->exitCode}.\n\nOutput:\n{$this->output}\n\nError:\n{$this->errorOutput}"
            );
        }

        return $this;
    }

    /** 
     * Check if the process was successful (exit code 0).
     *
     * @return bool True if the process was successful, false otherwise
     */
    public function successful(): bool
    {
        return $this->exitCode === 0;
    }

    /** 
     * Check if the process failed (non-zero exit code).
     *
     * @return bool True if the process failed, false otherwise
     */
    public function failed(): bool
    {
        return !$this->successful();
    }

    /** 
     * Get the standard output of the process.
     *
     * @return string The trimmed standard output
     */
    public function output(): string
    {
        return trim($this->output);
    }

    /** 
     * Get the error output of the process.
     *
     * @return string The trimmed error output
     */
    public function errorOutput(): string
    {
        return trim($this->errorOutput);
    }

    /** 
     * Get the exit code of the process.
     *
     * @return int|null The exit code, or null if the process has not been executed
     */
    public function exitCode(): ?int
    {
        return $this->exitCode;
    }

    /** 
     * Get the latest standard output of the process.
     *
     * @return string The trimmed latest standard output
     */
    public function latestOutput(): string
    {
        return $this->output();
    }

    /** 
     * Get the latest error output of the process.
     *
     * @return string The trimmed latest error output
     */
    public function latestErrorOutput(): string
    {
        return $this->errorOutput();
    }

    /** 
     * Get the execution time of the process in seconds.
     *
     * @return float The execution time in seconds
     */
    public function executionTime(): float
    {
        return $this->executionTime;
    }

    /** 
     * Get the command that was executed.
     *
     * @return string The command string
     */
    public function getCommand(): string
    {
        return $this->command;
    }

    /** 
     * Get the working directory for the process.
     *
     * @return string|null The working directory, or null if not set
     */
    public function __toString(): string
    {
        return $this->output();
    }
}