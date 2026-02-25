<?php

declare(strict_types=1);

namespace QuerySentinel\Support;

/**
 * Result of EXPLAIN / EXPLAIN ANALYZE execution.
 * No exceptions: success/failure is encoded in the result.
 */
final readonly class ExplainResult
{
    /**
     * @param  array<int, array<string, mixed>>  $explainRows
     */
    private function __construct(
        private bool $success,
        private string $plan,
        private array $explainRows,
        private ?ValidationFailureReport $failure,
    ) {}

    public static function success(string $plan, array $explainRows = []): self
    {
        return new self(true, $plan, $explainRows, null);
    }

    public static function failure(string $planOrMessage, ?ValidationFailureReport $failure = null): self
    {
        return new self(false, $planOrMessage, [], $failure);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getPlan(): string
    {
        return $this->plan;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getExplainRows(): array
    {
        return $this->explainRows;
    }

    public function getFailure(): ?ValidationFailureReport
    {
        return $this->failure;
    }
}
