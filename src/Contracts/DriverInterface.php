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

    /**
     * Normalize a raw access type string to a cross-driver canonical form.
     */
    public function normalizeAccessType(string $rawType): string;

    /**
     * Normalize a raw join type string to a cross-driver canonical form.
     */
    public function normalizeJoinType(string $rawType): string;

    /**
     * Run ANALYZE TABLE (or equivalent) to refresh statistics.
     */
    public function runAnalyzeTable(string $table): void;

    /**
     * Get column statistics for a specific table.column.
     *
     * @return array<string, mixed> Keys: has_histogram, distinct_count, null_fraction, avg_width
     */
    public function getColumnStats(string $table, string $column): array;

    /**
     * Get the driver's capability flags.
     *
     * @return array<string, bool> Possible keys: histograms, explain_analyze, json_explain, covering_index_info, parallel_query
     */
    public function getCapabilities(): array;
}
