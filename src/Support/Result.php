<?php

declare(strict_types=1);

namespace QuerySentinel\Support;

final readonly class Result
{
    /**
     * @param  array<int, array<string, mixed>>  $explainRows
     * @param  array<string, mixed>  $metrics
     * @param  array<string, mixed>  $scores
     * @param  array<int, array<string, mixed>>  $findings
     */
    public function __construct(
        public string $sql,
        public string $driver,
        public array $explainRows,
        public string $plan,
        public array $metrics,
        public array $scores,
        public array $findings,
        public float $executionTimeMs,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sql' => $this->sql,
            'driver' => $this->driver,
            'explain_rows' => $this->explainRows,
            'plan' => $this->plan,
            'metrics' => $this->metrics,
            'scores' => $this->scores,
            'findings' => $this->findings,
            'execution_time_ms' => $this->executionTimeMs,
        ];
    }
}
