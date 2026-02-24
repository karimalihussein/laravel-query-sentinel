<?php

declare(strict_types=1);

namespace QuerySentinel\Support;

use QuerySentinel\Contracts\DriverInterface;
use QuerySentinel\Exceptions\EngineAbortException;
use QuerySentinel\Exceptions\UnsafeQueryException;

/**
 * Hard guard around EXPLAIN ANALYZE execution.
 * Throws EngineAbortException on ANY failure — never returns error strings.
 */
final class ExplainGuard
{
    public function __construct(
        private readonly DriverInterface $driver,
    ) {}

    /**
     * Execute EXPLAIN ANALYZE. Throws on failure.
     *
     * @throws EngineAbortException
     */
    public function runExplainAnalyze(string $sql): string
    {
        try {
            $plan = $this->driver->runExplainAnalyze($sql);
        } catch (EngineAbortException $e) {
            throw $e;
        } catch (UnsafeQueryException $e) {
            throw new EngineAbortException(
                'EXPLAIN aborted: unsafe query',
                new ValidationFailureReport(
                    status: 'ERROR — Validation Failed',
                    failureStage: 'Explain',
                    detailedError: $e->getMessage(),
                    recommendations: ['Only SELECT queries can be analyzed'],
                ),
                $e
            );
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            $sqlstate = null;
            if (preg_match('/SQLSTATE\[(\w+)\]/', $message, $m)) {
                $sqlstate = $m[1];
            }

            throw new EngineAbortException(
                'EXPLAIN failed',
                new ValidationFailureReport(
                    status: 'ERROR — EXPLAIN Failed',
                    failureStage: 'Explain',
                    detailedError: $message,
                    sqlstateCode: $sqlstate,
                    recommendations: [
                        'Analysis aborted.',
                        'Verify the query executes successfully.',
                    ],
                ),
                $e
            );
        }

        if (str_starts_with(trim($plan), '-- EXPLAIN failed:')) {
            $msg = substr($plan, strlen('-- EXPLAIN failed:'));
            $sqlstate = null;
            if (preg_match('/SQLSTATE\[(\w+)\]/', $msg, $m)) {
                $sqlstate = $m[1];
            }

            throw new EngineAbortException(
                'EXPLAIN failed',
                new ValidationFailureReport(
                    status: 'ERROR — EXPLAIN Failed',
                    failureStage: 'Explain',
                    detailedError: trim($msg),
                    sqlstateCode: $sqlstate,
                    recommendations: ['Analysis aborted.'],
                )
            );
        }

        return $plan;
    }

    /**
     * Execute EXPLAIN (tabular). Returns empty array on failure.
     * Used for enrichment — does not block analysis if plan text was valid.
     */
    public function runExplain(string $sql): array
    {
        return $this->driver->runExplain($sql);
    }
}
