<?php

declare(strict_types=1);

namespace QuerySentinel\Analyzers;

use QuerySentinel\Enums\ComplexityClass;
use QuerySentinel\Support\PlanNode;

/**
 * Extracts structured performance metrics from a parsed EXPLAIN ANALYZE plan tree.
 *
 * Computes: execution time, rows examined/returned, selectivity, nested loop depth,
 * anti-pattern detection, complexity classification, and fanout estimation.
 */
final class MetricsExtractor
{
    /**
     * Extract comprehensive metrics from a plan tree and raw plan text.
     *
     * @return array<string, mixed>
     */
    public function extract(?PlanNode $root, string $rawPlan): array
    {
        if ($root === null) {
            return $this->emptyMetrics();
        }

        $allNodes = $root->flatten();

        $executionTimeMs = $root->actualTimeEnd ?? 0.0;
        $rowsReturned = $root->actualRows ?? 0;
        $rowsExamined = $this->sumRowsExamined($allNodes);
        $nestedLoopDepth = $this->countNestedLoops($allNodes);
        $maxLoops = $this->findMaxLoops($allNodes);
        $maxCost = $this->findMaxCost($allNodes);

        $hasTableScan = $this->detectTableScan($allNodes, $rawPlan);
        $hasTempTable = $this->detectPattern($rawPlan, '/temporary|Temp table|Using temporary/i');
        $hasFilesort = $this->detectPattern($rawPlan, '/filesort|\bSort:/i');
        $hasWeedout = $this->detectPattern($rawPlan, '/weedout/i');
        $hasIndexMerge = $this->detectPattern($rawPlan, '/index_merge|Index merge/i');
        $hasCoveringIndex = $this->detectPattern($rawPlan, '/Covering index/i');
        $isIndexBacked = $this->detectPattern($rawPlan, '/Index|index lookup|index range|Covering index|Full-text/i');
        $hasDiskTemp = $this->detectPattern($rawPlan, '/on disk|temporary table.*disk/i');
        $hasMaterialization = $this->detectPattern($rawPlan, '/Materialize/i');
        $hasEarlyTermination = $this->detectEarlyTermination($root, $allNodes);

        $indexesUsed = $this->collectIndexes($allNodes);
        $tablesAccessed = $this->collectTables($allNodes);
        $complexity = $this->classifyComplexity(
            $hasEarlyTermination, $isIndexBacked, $hasTableScan,
            $hasTempTable, $hasFilesort, $maxLoops, $nestedLoopDepth,
        );

        $fanoutFactor = $this->calculateFanout($allNodes);
        $joinCount = count($tablesAccessed);
        $selectivityRatio = $rowsReturned > 0
            ? round($rowsExamined / $rowsReturned, 2)
            : ($rowsExamined > 0 ? (float) $rowsExamined : 0.0);

        $perTableEstimates = $this->extractPerTableEstimates($allNodes);

        return [
            'execution_time_ms' => $executionTimeMs,
            'rows_examined' => $rowsExamined,
            'rows_returned' => $rowsReturned,
            'nested_loop_depth' => $nestedLoopDepth,
            'max_loops' => $maxLoops,
            'max_cost' => $maxCost,
            'has_temp_table' => $hasTempTable,
            'has_weedout' => $hasWeedout,
            'has_filesort' => $hasFilesort,
            'has_table_scan' => $hasTableScan,
            'has_index_merge' => $hasIndexMerge,
            'has_covering_index' => $hasCoveringIndex,
            'is_index_backed' => $isIndexBacked,
            'has_disk_temp' => $hasDiskTemp,
            'has_materialization' => $hasMaterialization,
            'has_early_termination' => $hasEarlyTermination,
            'complexity' => $complexity->value,
            'complexity_label' => $complexity->label(),
            'complexity_risk' => $complexity->riskLevel(),
            'fanout_factor' => $fanoutFactor,
            'join_count' => $joinCount,
            'selectivity_ratio' => $selectivityRatio,
            'indexes_used' => $indexesUsed,
            'tables_accessed' => $tablesAccessed,
            'node_count' => count($allNodes),
            'per_table_estimates' => $perTableEstimates,
        ];
    }

    /**
     * Sum rows examined across all I/O operations: actual_rows * loops.
     *
     * @param  PlanNode[]  $nodes
     */
    private function sumRowsExamined(array $nodes): int
    {
        $total = 0;

        foreach ($nodes as $node) {
            if ($node->isIoOperation()) {
                $total += $node->rowsProcessed();
            }
        }

        return $total;
    }

    /**
     * Count actual Nested loop join nodes (not indentation depth).
     *
     * @param  PlanNode[]  $nodes
     */
    private function countNestedLoops(array $nodes): int
    {
        $count = 0;

        foreach ($nodes as $node) {
            if (str_contains(strtolower($node->operation), 'nested loop')) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  PlanNode[]  $nodes
     */
    private function findMaxLoops(array $nodes): int
    {
        $max = 0;

        foreach ($nodes as $node) {
            if ($node->loops !== null) {
                $max = max($max, $node->loops);
            }
        }

        return $max;
    }

    /**
     * @param  PlanNode[]  $nodes
     */
    private function findMaxCost(array $nodes): float
    {
        $max = 0.0;

        foreach ($nodes as $node) {
            if ($node->estimatedCost !== null) {
                $max = max($max, $node->estimatedCost);
            }
        }

        return $max;
    }

    /**
     * Detect real table scans, excluding derived/temporary/subquery scans.
     *
     * @param  PlanNode[]  $nodes
     */
    private function detectTableScan(array $nodes, string $rawPlan): bool
    {
        if (! $this->detectPattern($rawPlan, '/\bTable scan\b/i')) {
            return false;
        }

        // Exclude non-problematic scans on derived/temporary tables
        $exclusions = ['<subquery', '<temporary>', 'drv'];

        foreach ($nodes as $node) {
            if ($node->accessType !== 'table_scan') {
                continue;
            }

            $tableName = $node->table ?? '';
            $isExcluded = false;

            foreach ($exclusions as $pattern) {
                if (str_contains($tableName, $pattern)) {
                    $isExcluded = true;

                    break;
                }
            }

            if (! $isExcluded) {
                return true;
            }
        }

        return false;
    }

    private function detectPattern(string $text, string $pattern): bool
    {
        return (bool) preg_match($pattern, $text);
    }

    /**
     * Detect LIMIT early termination: root has Limit and estimated >> actual.
     *
     * @param  PlanNode[]  $allNodes
     */
    private function detectEarlyTermination(PlanNode $root, array $allNodes): bool
    {
        $hasLimit = str_contains(strtolower($root->operation), 'limit');

        if (! $hasLimit) {
            // Check if any top-level node is a Limit
            foreach ($allNodes as $node) {
                if (str_contains(strtolower($node->operation), 'limit')) {
                    $hasLimit = true;

                    break;
                }
            }
        }

        if (! $hasLimit) {
            return false;
        }

        // Find evidence: single-pass node where estimated >> actual
        foreach ($allNodes as $node) {
            if ($node->loops !== 1 || $node->estimatedRows === null || $node->actualRows === null) {
                continue;
            }

            $estimated = (int) $node->estimatedRows;
            $actual = $node->actualRows;

            if ($estimated > ($actual * 5) && $actual >= 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Collect unique index names used in the plan.
     *
     * @param  PlanNode[]  $nodes
     * @return array<int, string>
     */
    private function collectIndexes(array $nodes): array
    {
        $indexes = [];

        foreach ($nodes as $node) {
            if ($node->index !== null && $node->index !== '') {
                $indexes[] = $node->index;
            }
        }

        return array_values(array_unique($indexes));
    }

    /**
     * Collect unique table names accessed in the plan.
     *
     * @param  PlanNode[]  $nodes
     * @return array<int, string>
     */
    private function collectTables(array $nodes): array
    {
        $tables = [];

        foreach ($nodes as $node) {
            if ($node->table !== null && $node->table !== '') {
                $tables[] = $node->table;
            }
        }

        return array_values(array_unique($tables));
    }

    /**
     * Classify query time complexity based on plan characteristics.
     */
    private function classifyComplexity(
        bool $hasEarlyTermination,
        bool $isIndexBacked,
        bool $hasTableScan,
        bool $hasTempTable,
        bool $hasFilesort,
        int $maxLoops,
        int $nestedLoopDepth,
    ): ComplexityClass {
        // Best case: LIMIT + index = early termination
        if ($hasEarlyTermination && $isIndexBacked && ! $hasTempTable) {
            return ComplexityClass::Limit;
        }

        // Quadratic: nested loop explosion or table scan + high loops
        if ($hasTableScan && $maxLoops > 10_000) {
            return ComplexityClass::Quadratic;
        }

        if ($nestedLoopDepth > 3 && $maxLoops > 1_000) {
            return ComplexityClass::Quadratic;
        }

        // Linearithmic: filesort on non-trivial set
        if ($hasFilesort && ! $hasEarlyTermination) {
            return ComplexityClass::Linearithmic;
        }

        // Linear: full table scan without loop amplification
        if ($hasTableScan) {
            return ComplexityClass::Linear;
        }

        // Range: index-backed but no early termination
        if ($isIndexBacked) {
            return ComplexityClass::Range;
        }

        return ComplexityClass::Linear;
    }

    /**
     * Calculate the maximum fanout factor across all join levels.
     *
     * Fanout = max(actual_rows * loops) for any single table access.
     *
     * @param  PlanNode[]  $nodes
     */
    private function calculateFanout(array $nodes): float
    {
        $maxFanout = 1.0;

        foreach ($nodes as $node) {
            if ($node->isIoOperation() && $node->actualRows !== null && $node->loops !== null) {
                $fanout = (float) ($node->actualRows * $node->loops);
                $maxFanout = max($maxFanout, $fanout);
            }
        }

        return $maxFanout;
    }

    /**
     * Extract per-table row/loop estimates for breakdown reporting.
     *
     * @param  PlanNode[]  $nodes
     * @return array<string, array{estimated_rows: float, actual_rows: int, loops: int}>
     */
    private function extractPerTableEstimates(array $nodes): array
    {
        $tables = [];

        foreach ($nodes as $node) {
            if ($node->table === null || ! $node->isIoOperation()) {
                continue;
            }

            $table = $node->table;
            $rows = $node->actualRows ?? 0;
            $loops = $node->loops ?? 1;
            $estimated = $node->estimatedRows ?? 0.0;

            if (! isset($tables[$table]) || ($rows * $loops) > ($tables[$table]['actual_rows'] * $tables[$table]['loops'])) {
                $tables[$table] = [
                    'estimated_rows' => $estimated,
                    'actual_rows' => $rows,
                    'loops' => $loops,
                ];
            }
        }

        return $tables;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyMetrics(): array
    {
        return [
            'execution_time_ms' => 0.0,
            'rows_examined' => 0,
            'rows_returned' => 0,
            'nested_loop_depth' => 0,
            'max_loops' => 0,
            'max_cost' => 0.0,
            'has_temp_table' => false,
            'has_weedout' => false,
            'has_filesort' => false,
            'has_table_scan' => false,
            'has_index_merge' => false,
            'has_covering_index' => false,
            'is_index_backed' => false,
            'has_disk_temp' => false,
            'has_materialization' => false,
            'has_early_termination' => false,
            'complexity' => ComplexityClass::Linear->value,
            'complexity_label' => ComplexityClass::Linear->label(),
            'complexity_risk' => ComplexityClass::Linear->riskLevel(),
            'fanout_factor' => 1.0,
            'join_count' => 0,
            'selectivity_ratio' => 0.0,
            'indexes_used' => [],
            'tables_accessed' => [],
            'node_count' => 0,
            'per_table_estimates' => [],
        ];
    }
}
