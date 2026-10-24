<?php

namespace Spark;

use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Spark\Contracts\LoggerInterface;
use Stringable;
use function in_array;
use function is_scalar;
use function json_encode;
use function str_contains;
use function strtoupper;
use function strtr;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Native lightweight PSR-3 Logger for Spark.
 */
class Logger extends AbstractLogger implements LoggerInterface
{
    /**
     * Logs with an arbitrary level.
     *
     * @param mixed $level
     * @param string|Stringable $message
     * @param array $context
     * @return void
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (!in_array($level, ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'], true)) {
            throw new InvalidArgumentException('Unknown log level.');
        }

        $levelUpper = strtoupper((string) $level);
        $msg = (string) $message;

        if (!empty($context) && str_contains($msg, '{')) {
            $replace = [];
            foreach ($context as $key => $val) {
                if ($val === null || is_scalar($val) || $val instanceof Stringable) {
                    try {
                        $replace['{' . $key . '}'] = (string) $val;
                    } catch (\Throwable) {
                        // PSR-3 context must not cause logging to fail.
                    }
                }
            }
            $msg = strtr($msg, $replace);
        }

        try {
            $contextStr = $context ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) : '';
        } catch (\Throwable) {
            $contextStr = '';
        }

        if (!isset(Tracer::$instance)) {
            Tracer::start();
        }

        Tracer::$instance->log("local.$levelUpper: $msg$contextStr");
    }
}
