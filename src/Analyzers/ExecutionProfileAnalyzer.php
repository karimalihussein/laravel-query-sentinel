<?php

declare(strict_types=1);

namespace QuerySentinel\Analyzers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use QuerySentinel\Enums\ComplexityClass;
use QuerySentinel\Enums\Severity;
use QuerySentinel\Support\ExecutionProfile;
use QuerySentinel\Support\Finding;

/**
 * Section 2: Deep Execution Metrics.
 *
 * Extracts nested loop depth, join fanouts, B-tree depth estimates,
 * logical/physical read approximations, and complexity classification
 * from EXPLAIN ANALYZE output and SHOW INDEX metadata.
 */
final class ExecutionProfileAnalyzer
{
    /**
     * @param  string  $plan  EXPLAIN ANALYZE output
     * @param  array<string, mixed>  $metrics  From MetricsExtractor
     * @param  array<int, array<string, mixed>>  $explainRows  From EXPLAIN tabular output
     * @return array{profile: ExecutionProfile, findings: Finding[]}
     */
    public function analyze(string $plan, array $metrics, array $explainRows, ?string $connectionName = null): array
    {
        $findings = [];

        $nestedLoopDepth = $this->countNestedLoopDepth($plan);
        $joinFanouts = $this->extractJoinFanouts($plan);
        $btreeDepths = $this->estimateBTreeDepths($explainRows, $connectionName);
        [$logicalReads, $physicalReads] = $this->approximateReads($metrics, $connectionName);
        $scanComplexity = $this->classifyScanComplexity($plan, $metrics);
        $sortComplexity = $this->classifySortComplexity($metrics);

        if ($nestedLoopDepth > 3) {
            $findings[] = new Finding(
                severity: Severity::Warning,
                category: 'execution_metrics',
                title: sprintf('Deep nested loop nesting: %d levels', $nestedLoopDepth),
                description: 'Deeply nested loops amplify row processing exponentially. Consider restructuring joins or using derived tables.',
                recommendation: 'Reduce join depth by pre-filtering with subqueries or denormalized tables.',
                metadata: ['depth' => $nestedLoopDepth],
            );
        }

        if ($logicalReads > 0 && $physicalReads > 0) {
            $physicalRatio = $physicalReads / max($logicalReads, 1);
            if ($physicalRatio > 0.3) {
                $findings[] = new Finding(
                    severity: Severity::Warning,
                    category: 'execution_metrics',
                    title: sprintf('High physical read ratio: %.0f%%', $physicalRatio * 100),
                    description: 'Over 30% of reads are hitting disk rather than the buffer pool. Performance may improve significantly once data is cached.',
                    recommendation: 'Increase innodb_buffer_pool_size or warm the cache with representative queries.',
                    metadata: ['physical_ratio' => round($physicalRatio, 4), 'logical_reads' => $logicalReads, 'physical_reads' => $physicalReads],
                );
            }
        }

        $profile = new ExecutionProfile(
            nestedLoopDepth: $nestedLoopDepth,
            joinFanouts: $joinFanouts,
            btreeDepths: $btreeDepths,
            logicalReads: $logicalReads,
            physicalReads: $physicalReads,
            scanComplexity: $scanComplexity,
            sortComplexity: $sortComplexity,
        );

        return ['profile' => $profile, 'findings' => $findings];
    }

    private function countNestedLoopDepth(string $plan): int
    {
        return (int) preg_match_all('/Nested loop/i', $plan);
    }

    /**
     * @return array<string, float> table => actual_rows * loops
     */
    private function extractJoinFanouts(string $plan): array
    {
        $fanouts = [];
        $pattern = '/(?:scan|lookup|search)\s+on\s+(\w+).*?\(actual\s+time=[\d.]+\.\.[\d.]+\s+rows=(\d+)\s+loops=(\d+)\)/is';

        if (preg_match_all($pattern, $plan, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $table = $match[1];
                $rows = (int) $match[2];
                $loops = (int) $match[3];
                $fanouts[$table] = max($fanouts[$table] ?? 0, $rows * $loops);
            }
        }

        return $fanouts;
    }

    /**
     * Estimate B-tree depth from SHOW INDEX cardinality.
     *
     * InnoDB stores ~500 keys per page on average, so depth = ceil(log_500(cardinality)).
     *
     * @return array<string, int> index_name => estimated_depth
     */
    private function estimateBTreeDepths(array $explainRows, ?string $connectionName = null): array
    {
        $depths = [];
        $tables = array_unique(array_filter(array_column($explainRows, 'table')));

        foreach ($tables as $table) {
            if (str_starts_with($table, '<')) {
                continue;
            }
            $indexes = $this->getCachedIndexInfo($table, $connectionName);
            $seen = [];
            foreach ($indexes as $idx) {
                $keyName = $idx->Key_name;
                if (isset($seen[$keyName])) {
                    continue;
                }
                $seen[$keyName] = true;

                $cardinality = (int) ($idx->Cardinality ?? 0);
                if ($cardinality > 0) {
                    $depths[$keyName] = max(1, (int) ceil(log($cardinality) / log(500)));
                }
            }
        }

        return $depths;
    }

    /**
     * @return array<int, object>
     */
    private function getCachedIndexInfo(string $table, ?string $connectionName = null): array
    {
        $connection = DB::connection($connectionName);
        $dbName = $connection->getDatabaseName();
        $cacheKey = "query_sentinel_show_index_{$table}_{$dbName}";

        return Cache::remember($cacheKey, 300, function () use ($connection, $table) {
            try {
                return $connection->select("SHOW INDEX FROM `{$table}`");
            } catch (\Exception) {
                return [];
            }
        });
    }

    /**
     * Approximate logical/physical reads from buffer pool hit ratio.
     *
     * @return array{0: int, 1: int} [logical_reads, physical_reads]
     */
    private function approximateReads(array $metrics, ?string $connectionName = null): array
    {
        $logicalReads = $metrics['rows_examined'] ?? 0;

        $connection = DB::connection($connectionName);
        $dbName = $connection->getDatabaseName();
        $cacheKey = "query_sentinel_bp_hit_ratio_{$dbName}";

        $missRatio = Cache::remember($cacheKey, 300, function () use ($connection) {
            try {
                $rows = $connection->select("SHOW STATUS WHERE Variable_name IN (
                    'Innodb_buffer_pool_read_requests',
                    'Innodb_buffer_pool_reads'
                )");
                $status = collect($rows)->pluck('Value', 'Variable_name');
                $requests = (int) ($status['Innodb_buffer_pool_read_requests'] ?? 1);
                $reads = (int) ($status['Innodb_buffer_pool_reads'] ?? 0);

                return $requests > 0 ? $reads / $requests : 0.01;
            } catch (\Exception) {
                return 0.01;
            }
        });

        $physicalReads = (int) round($logicalReads * $missRatio);

        return [$logicalReads, $physicalReads];
    }

    /**
     * Classify scan complexity from access type in metrics.
     *
     * Uses the pre-computed complexity from MetricsExtractor which is
     * derived from actual access types (not heuristics).
     */
    private function classifyScanComplexity(string $plan, array $metrics): ComplexityClass
    {
        $complexityValue = $metrics['complexity'] ?? null;
        if ($complexityValue !== null) {
            $complexity = ComplexityClass::tryFrom($complexityValue);
            if ($complexity !== null) {
                return $complexity;
            }
        }

        // Fallback: derive from access type
        $accessType = $metrics['primary_access_type'] ?? null;

        return match ($accessType) {
            'zero_row_const', 'const_row', 'single_row_lookup' => ComplexityClass::Constant,
            'covering_index_lookup', 'index_lookup', 'fulltext_index' => ComplexityClass::Logarithmic,
            'index_range_scan' => ComplexityClass::LogRange,
            'index_scan' => ComplexityClass::Linear,
            'table_scan' => ComplexityClass::Linear,
            default => ComplexityClass::Linear,
        };
    }

    /**
     * Classify sort complexity.
     *
     * No sort needed → O(1) (constant, no work).
     * Filesort → O(n log n).
     */
    private function classifySortComplexity(array $metrics): ComplexityClass
    {
        if (! ($metrics['has_filesort'] ?? false)) {
            return ComplexityClass::Constant;
        }

        return ComplexityClass::Linearithmic;
    }
}
