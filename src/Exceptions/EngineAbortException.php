<?php

declare(strict_types=1);

namespace QuerySentinel\Exceptions;

use QuerySentinel\Support\ValidationFailureReport;

/**
 * Thrown when the engine must abort because validation failed or EXPLAIN produced no valid plan.
 * Never produce a performance report when this is thrown.
 */
final class EngineAbortException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ValidationFailureReport $failureReport,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
