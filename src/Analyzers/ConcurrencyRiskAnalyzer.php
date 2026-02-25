<?php

declare(strict_types=1);

namespace QuerySentinel\Analyzers;

use QuerySentinel\Enums\Severity;
use QuerySentinel\Support\ExecutionProfile;
use QuerySentinel\Support\Finding;

/**
 * Phase 6: Concurrency Risk Model.
 *
 * Assesses lock contention risk, deadlock potential, and transaction isolation
 * impact for a given SQL query. Produces a risk profile covering lock scope,
 * deadlock scoring, contention estimation, and isolation-level guidance.
 */
final class ConcurrencyRiskAnalyzer
{
    /**
     * @param  string  $sql  Raw SQL query
     * @param  array<string, mixed>  $metrics  From MetricsExtractor
     * @param  ExecutionProfile|null  $profile  Execution profile
     * @return array{concurrency: array<string, mixed>, findings: Finding[]}
     */
    public function analyze(string $sql, array $metrics, ?ExecutionProfile $profile): array
    {
        $isWriteQuery = $this->isWriteQuery($sql);
        $isLockingRead = $this->isLockingRead($sql);

        // Plain SELECT under InnoDB MVCC: consistent read, no locks acquired.
        // Only write queries and locking reads (FOR UPDATE / FOR SHARE) acquire locks.
        if (! $isWriteQuery && ! $isLockingRead) {
            return [
                'concurrency' => [
                    'lock_scope' => 'none',
                    'deadlock_risk' => 0.0,
                    'deadlock_risk_label' => 'none',
                    'contention_score' => 0.0,
                    'isolation_impact' => 'Read queries use consistent reads (MVCC snapshots). No locks acquired under default isolation level.',
                    'recommendations' => [],
                ],
                'findings' => [],
            ];
        }

        $findings = [];

        $lockScope = $this->assessLockScope($metrics);
        $isMultiTable = $this->isMultiTableQuery($sql);
        $hasSubquery = $this->hasSubquery($sql);

        $deadlockRisk = $this->scoreDeadlockRisk($metrics, $profile, $isMultiTable, $hasSubquery);
        $deadlockRiskLabel = $this->labelDeadlockRisk($deadlockRisk);

        $contentionScore = $this->estimateContention($metrics, $profile);

        $isolationImpact = $this->describeIsolationImpact($lockScope, $isWriteQuery);

        $recommendations = $this->buildRecommendations(
            $lockScope,
            $deadlockRiskLabel,
            $contentionScore,
            $isWriteQuery,
        );

        // Generate findings
        if ($isWriteQuery && $lockScope === 'table') {
            $findings[] = new Finding(
                severity: Severity::Critical,
                category: 'concurrency',
                title: 'Write query with full table scan',
                description: 'A write query (UPDATE/DELETE/INSERT) is performing a full table scan, which acquires gap locks on all scanned ranges and can severely block concurrent operations.',
                recommendation: 'Add an index to avoid full table scan and reduce lock scope from table-level to row-level.',
                metadata: [
                    'lock_scope' => $lockScope,
                    'deadlock_risk' => $deadlockRisk,
                ],
            );
        } elseif ($lockScope === 'table') {
            $findings[] = new Finding(
                severity: Severity::Warning,
                category: 'concurrency',
                title: 'Table-level lock scope detected',
                description: 'Full table scan causes InnoDB to acquire gap locks on all scanned ranges under REPEATABLE READ, significantly increasing lock contention.',
                recommendation: 'Add an index to avoid full table scan and reduce lock scope from table-level to row-level.',
                metadata: [
                    'lock_scope' => $lockScope,
                    'deadlock_risk' => $deadlockRisk,
                ],
            );
        }

        if ($deadlockRiskLabel === 'high') {
            $findings[] = new Finding(
                severity: Severity::Warning,
                category: 'concurrency',
                title: 'High deadlock risk',
                description: sprintf(
                    'Deadlock risk score is %.2f (high). The query touches multiple tables or lacks index coverage, increasing the chance of circular lock waits.',
                    $deadlockRisk,
                ),
                recommendation: 'Consider breaking the query into smaller transactions, or ensure consistent table access ordering.',
                metadata: [
                    'deadlock_risk' => $deadlockRisk,
                    'deadlock_risk_label' => $deadlockRiskLabel,
                ],
            );
        } elseif ($deadlockRiskLabel === 'moderate') {
            $findings[] = new Finding(
                severity: Severity::Optimization,
                category: 'concurrency',
                title: 'Moderate deadlock risk',
                description: sprintf(
                    'Deadlock risk score is %.2f (moderate). Consider reviewing transaction scope and lock ordering.',
                    $deadlockRisk,
                ),
                recommendation: 'Consider breaking the query into smaller transactions, or ensure consistent table access ordering.',
                metadata: [
                    'deadlock_risk' => $deadlockRisk,
                    'deadlock_risk_label' => $deadlockRiskLabel,
                ],
            );
        }

        if ($contentionScore > 100) {
            $findings[] = new Finding(
                severity: Severity::Warning,
                category: 'concurrency',
                title: 'High lock contention score',
                description: sprintf(
                    'Lock contention score is %.4f. Long execution time combined with high rows examined indicates sustained lock holding.',
                    $contentionScore,
                ),
                recommendation: 'Consider using SELECT ... FOR UPDATE SKIP LOCKED or NOWAIT for queue-like access patterns.',
                metadata: [
                    'contention_score' => $contentionScore,
                ],
            );
        }

        return [
            'concurrency' => [
                'lock_scope' => $lockScope,
                'deadlock_risk' => $deadlockRisk,
                'deadlock_risk_label' => $deadlockRiskLabel,
                'contention_score' => $contentionScore,
                'isolation_impact' => $isolationImpact,
                'recommendations' => $recommendations,
            ],
            'findings' => $findings,
        ];
    }

    /**
     * Assess the lock scope based on access type and table scan indicators.
     *
     * @param  array<string, mixed>  $metrics
     */
    private function assessLockScope(array $metrics): string
    {
        $hasTableScan = (bool) ($metrics['has_table_scan'] ?? false);
        $primaryAccessType = $metrics['primary_access_type'] ?? null;

        if ($hasTableScan || $primaryAccessType === 'table_scan') {
            return 'table';
        }

        if ($primaryAccessType !== null && is_string($primaryAccessType) && str_contains($primaryAccessType, 'range')) {
            return 'range';
        }

        if (in_array($primaryAccessType, ['index_lookup', 'covering_index_lookup', 'index_scan'], true)) {
            return 'gap';
        }

        if (in_array($primaryAccessType, ['const_row', 'single_row_lookup', 'zero_row_const'], true)) {
            return 'row';
        }

        if ($primaryAccessType === null) {
            return 'unknown';
        }

        return 'unknown';
    }

    /**
     * Score deadlock risk from 0.0 to 1.0.
     *
     * @param  array<string, mixed>  $metrics
     */
    private function scoreDeadlockRisk(
        array $metrics,
        ?ExecutionProfile $profile,
        bool $isMultiTable,
        bool $hasSubquery,
    ): float {
        $risk = 0.0;

        if ($isMultiTable) {
            $risk += 0.3;
        }

        if ($hasSubquery) {
            $risk += 0.2;
        }

        $isIndexBacked = (bool) ($metrics['is_index_backed'] ?? false);
        $primaryAccessType = $metrics['primary_access_type'] ?? null;
        $isConstOrZero = in_array($primaryAccessType, ['const_row', 'zero_row_const'], true);

        if (! $isIndexBacked && ! $isConstOrZero) {
            $risk += 0.3;
        }

        $nestedLoopDepth = $profile?->nestedLoopDepth ?? (int) ($metrics['nested_loop_depth'] ?? 0);

        if ($nestedLoopDepth > 2) {
            $risk += 0.2;
        }

        return min(1.0, max(0.0, round($risk, 2)));
    }

    /**
     * Label deadlock risk as low, moderate, or high.
     */
    private function labelDeadlockRisk(float $risk): string
    {
        if ($risk > 0.6) {
            return 'high';
        }

        if ($risk >= 0.3) {
            return 'moderate';
        }

        return 'low';
    }

    /**
     * Estimate lock contention based on execution time and rows examined.
     *
     * @param  array<string, mixed>  $metrics
     */
    private function estimateContention(array $metrics, ?ExecutionProfile $profile): float
    {
        $executionTimeMs = (float) ($metrics['execution_time_ms'] ?? 0);
        $nestedDepth = $profile?->nestedLoopDepth ?? (int) ($metrics['nested_loop_depth'] ?? 1);
        $rowsExamined = (int) ($metrics['rows_examined'] ?? 0);

        $lockDurationFactor = $executionTimeMs * (1 + $nestedDepth * 0.5);
        $contentionScore = round($lockDurationFactor * $rowsExamined / 10000, 4);

        return $contentionScore;
    }

    /**
     * Describe the impact of transaction isolation level on the query.
     */
    private function describeIsolationImpact(string $lockScope, bool $isWriteQuery): string
    {
        if (! $isWriteQuery) {
            return 'Read queries use consistent reads (MVCC snapshots) and do not acquire locks under default isolation level.';
        }

        return match ($lockScope) {
            'table' => 'Under REPEATABLE READ, full table scans acquire gap locks on all scanned ranges, significantly increasing lock contention. Consider READ COMMITTED for reduced locking.',
            'range' => 'Range scans hold next-key locks under REPEATABLE READ, blocking inserts into scanned ranges.',
            'row' => 'Point lookups hold minimal locks. Safe under any isolation level.',
            default => 'Lock behavior depends on the specific access pattern and isolation level in use.',
        };
    }

    /**
     * Build actionable recommendations based on analysis results.
     *
     * @return string[]
     */
    private function buildRecommendations(
        string $lockScope,
        string $deadlockRiskLabel,
        float $contentionScore,
        bool $isWriteQuery,
    ): array {
        $recommendations = [];

        if ($lockScope === 'table') {
            $recommendations[] = 'Add an index to avoid full table scan and reduce lock scope from table-level to row-level.';
        }

        if ($deadlockRiskLabel === 'high') {
            $recommendations[] = 'Consider breaking the query into smaller transactions, or ensure consistent table access ordering.';
        }

        if ($contentionScore > 100) {
            $recommendations[] = 'Consider using SELECT ... FOR UPDATE SKIP LOCKED or NOWAIT for queue-like access patterns.';
        }

        if ($isWriteQuery && $lockScope === 'range') {
            $recommendations[] = 'Narrow the range condition to reduce the gap lock span.';
        }

        return $recommendations;
    }

    /**
     * Detect whether the SQL is a write query (UPDATE, DELETE, INSERT, REPLACE).
     */
    private function isWriteQuery(string $sql): bool
    {
        return (bool) preg_match('/^\s*(UPDATE|DELETE|INSERT|REPLACE)\b/i', $sql);
    }

    /**
     * Detect whether the SELECT is a locking read (FOR UPDATE / FOR SHARE / LOCK IN SHARE MODE).
     */
    private function isLockingRead(string $sql): bool
    {
        return (bool) preg_match('/\b(FOR\s+UPDATE|FOR\s+SHARE|LOCK\s+IN\s+SHARE\s+MODE)\b/i', $sql);
    }

    /**
     * Detect whether the query touches multiple tables (JOIN keyword present).
     */
    private function isMultiTableQuery(string $sql): bool
    {
        return (bool) preg_match('/\bJOIN\b/i', $sql);
    }

    /**
     * Detect whether the query contains a subquery.
     *
     * For SELECT statements, more than one SELECT keyword indicates a subquery.
     * For write statements (UPDATE/DELETE/INSERT/REPLACE), any embedded SELECT
     * keyword indicates a subquery.
     */
    private function hasSubquery(string $sql): bool
    {
        $count = preg_match_all('/\bSELECT\b/i', $sql);

        // Write queries: any SELECT keyword inside them is a subquery
        if ($this->isWriteQuery($sql)) {
            return $count >= 1;
        }

        // SELECT queries: more than one SELECT means a subquery
        return $count > 1;
    }
}
