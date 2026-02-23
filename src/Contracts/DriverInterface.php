<?php

declare(strict_types=1);

namespace QuerySentinel\Contracts;

interface DriverInterface
{
    /**
     * Execute EXPLAIN (or equivalent) for the given SQL.
     *
     * @return array<int, array<string, mixed>>
     */
    public function runExplain(string $sql): array;

    /**
     * Execute EXPLAIN ANALYZE (or equivalent) and return the plan text.
     */
    public function runExplainAnalyze(string $sql): string;

    /**
     * Get the driver name identifier (e.g., 'mysql', 'pgsql').
     */
    public function getName(): string;

    /**
     * Whether this driver supports EXPLAIN ANALYZE.
     */
    public function supportsAnalyze(): bool;
}
