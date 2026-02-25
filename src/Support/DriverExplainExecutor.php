<?php

declare(strict_types=1);

namespace QuerySentinel\Support;

use QuerySentinel\Contracts\DriverInterface;
use QuerySentinel\Contracts\ExplainExecutorInterface;
use QuerySentinel\Exceptions\UnsafeQueryException;

/**
 * Runs EXPLAIN via the configured driver. Returns ExplainResult; no exceptions.
 */
final class DriverExplainExecutor implements ExplainExecutorInterface
{
    public function __construct(
        private readonly DriverInterface $driver,
    ) {}

    public function execute(string $sql): ExplainResult
    {
        try {
            $plan = $this->driver->runExplainAnalyze($sql);
        } catch (UnsafeQueryException $e) {
            return ExplainResult::failure($e->getMessage(), new ValidationFailureReport(
                status: 'ERROR — Validation Failed',
                failureStage: 'Explain',
                detailedError: $e->getMessage(),
                recommendations: ['Only SELECT queries can be analyzed'],
            ));
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            $sqlstate = null;
            if (preg_match('/SQLSTATE\[(\w+)\]/', $message, $m)) {
                $sqlstate = $m[1];
            }

            return ExplainResult::failure($message, new ValidationFailureReport(
                status: 'ERROR — EXPLAIN Failed',
                failureStage: 'Explain',
                detailedError: $message,
                sqlstateCode: $sqlstate,
                recommendations: [
                    'Analysis aborted.',
                    'Verify the query executes successfully.',
                ],
            ));
        }

        if (str_starts_with(trim($plan), '-- EXPLAIN failed:')) {
            $msg = trim(substr($plan, strlen('-- EXPLAIN failed:')));
            $sqlstate = null;
            if (preg_match('/SQLSTATE\[(\w+)\]/', $msg, $m)) {
                $sqlstate = $m[1];
            }

            return ExplainResult::failure($msg, new ValidationFailureReport(
                status: 'ERROR — EXPLAIN Failed',
                failureStage: 'Explain',
                detailedError: $msg,
                sqlstateCode: $sqlstate,
                recommendations: ['Analysis aborted.'],
            ));
        }

        $explainRows = [];
        try {
            $explainRows = $this->driver->runExplain($sql);
        } catch (\Throwable) {
            // Enrichment only; plan is valid
        }

        return ExplainResult::success($plan, $explainRows);
    }
}
