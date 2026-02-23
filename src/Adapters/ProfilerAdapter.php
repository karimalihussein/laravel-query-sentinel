<?php

declare(strict_types=1);

namespace QuerySentinel\Adapters;

use Illuminate\Support\Facades\DB;
use QuerySentinel\Contracts\AnalyzerInterface;
use QuerySentinel\Contracts\ProfilerInterface;
use QuerySentinel\Core\ProfileReport;
use QuerySentinel\Support\ExecutionGuard;
use QuerySentinel\Support\QueryCapture;
use QuerySentinel\Support\SqlSanitizer;

/**
 * Profiler adapter for capturing and analyzing all queries from a closure.
 *
 * Execution flow:
 *   1. Start DB::listen() to capture queries
 *   2. Begin database transaction (safety net)
 *   3. Execute the user's callback
 *   4. Rollback transaction (prevent side effects)
 *   5. Stop capturing
 *   6. For each captured SELECT query, run through core analyzer
 *   7. Aggregate results into ProfileReport
 *
 * Safety guarantees:
 *   - Transaction wrapping prevents any writes from persisting
 *   - Non-SELECT queries are logged but not analyzed
 *   - EXPLAIN queries from analysis don't get captured (analysis runs after capture stops)
 *   - Exceptions in callback are caught and transaction is rolled back
 */
final class ProfilerAdapter implements ProfilerInterface
{
    public function __construct(
        private readonly AnalyzerInterface $analyzer,
        private readonly ExecutionGuard $guard,
        private readonly SqlSanitizer $sanitizer,
    ) {}

    public function profile(\Closure $callback): ProfileReport
    {
        $captures = [];
        $listening = true;

        // Register query listener (listening guard for any late-firing callbacks)
        DB::listen(function ($query) use (&$captures, &$listening) {
            if (! $listening) { // @phpstan-ignore-line
                return;
            }

            $captures[] = new QueryCapture(
                sql: $query->sql,
                bindings: $query->bindings ?? [],
                timeMs: $query->time ?? 0.0,
                connection: $query->connectionName ?? null,
            );
        });

        // Execute callback inside transaction for safety
        try {
            DB::beginTransaction();
            $callback();
        } catch (\Throwable $e) {
            // Swallow — we still want to analyze whatever queries ran
        } finally {
            try {
                DB::rollBack();
            } catch (\Throwable) {
                // Transaction may not exist if callback failed before any DB interaction
            }

            $listening = false;
        }

        return $this->analyzeCaptures($captures);
    }

    /**
     * Analyze all captured queries and aggregate into a ProfileReport.
     *
     * @param  array<int, QueryCapture>  $captures
     */
    private function analyzeCaptures(array $captures): ProfileReport
    {
        $reports = [];
        $skippedQueries = [];

        foreach ($captures as $capture) {
            $interpolatedSql = $capture->toInterpolatedSql();
            $sanitizedSql = $this->sanitizer->sanitize($interpolatedSql);

            // Only analyze SELECT queries
            if (! $this->guard->isSelect($sanitizedSql)) {
                $skippedQueries[] = $sanitizedSql;

                continue;
            }

            // Skip if the guard would reject it (e.g., malformed SQL)
            if (! $this->guard->isSafe($sanitizedSql)) {
                $skippedQueries[] = $sanitizedSql;

                continue;
            }

            try {
                $reports[] = $this->analyzer->analyze($sanitizedSql, 'profiler');
            } catch (\Throwable) {
                // Skip queries that fail analysis (e.g., temp tables no longer exist)
                $skippedQueries[] = $sanitizedSql;
            }
        }

        return ProfileReport::fromCaptures($captures, $reports, $skippedQueries);
    }
}
