<?php

declare(strict_types=1);

namespace QuerySentinel\Support;

use QuerySentinel\Contracts\DriverInterface;
use QuerySentinel\Contracts\ExplainExecutorInterface;
use QuerySentinel\Exceptions\EngineAbortException;

/**
 * EXPLAIN execution: optional exception path for backward compatibility.
 * Prefer ExplainExecutorInterface for result-based flow (no exceptions).
 */
final class ExplainGuard
{
    public function __construct(
        private readonly DriverInterface $driver,
        private readonly ?ExplainExecutorInterface $executor = null,
    ) {}

    /**
     * Execute EXPLAIN ANALYZE. Returns result; does not throw.
     */
    public function runExplainAnalyzeResult(string $sql): ExplainResult
    {
        $executor = $this->executor ?? new DriverExplainExecutor($this->driver);

        return $executor->execute($sql);
    }

    /**
     * Execute EXPLAIN ANALYZE. Throws EngineAbortException on failure (legacy).
     *
     * @throws EngineAbortException
     */
    public function runExplainAnalyze(string $sql): string
    {
        $result = $this->runExplainAnalyzeResult($sql);

        if (! $result->isSuccess()) {
            $failure = $result->getFailure();

            throw new EngineAbortException(
                'EXPLAIN failed',
                $failure ?? new ValidationFailureReport(
                    status: 'ERROR — EXPLAIN Failed',
                    failureStage: 'Explain',
                    detailedError: $result->getPlan(),
                    recommendations: ['Analysis aborted.'],
                )
            );
        }

        return $result->getPlan();
    }

    /**
     * Execute EXPLAIN (tabular). Returns empty array on failure.
     */
    public function runExplain(string $sql): array
    {
        return $this->driver->runExplain($sql);
    }
}
