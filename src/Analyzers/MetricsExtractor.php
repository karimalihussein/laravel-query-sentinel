<?php

declare(strict_types=1);

namespace QuerySentinel\Analyzers;

use QuerySentinel\Enums\ComplexityClass;
use QuerySentinel\Support\PlanNode;

/**
 * Extracts structured performance metrics from a parsed EXPLAIN ANALYZE plan tree.
 *
 * All detection is driven by the parsed plan tree nodes and their access types.
 * No heuristic regex guessing — access types are classified by the parser from
 * actual EXPLAIN ANALYZE operation descriptions.
 *
 * Computes: execution time, rows examined/returned, selectivity, nested loop depth,
 * anti-pattern detection, complexity classification, and fanout estimation.
 */
final class MetricsExtractor
{
    /**
     * Access types ordered by severity (worst = highest index).
     * Used to determine the "primary" (worst) access type across all nodes.
     *
     * @var array<string, int>
     */
    private const ACCESS_TYPE_SEVERITY = [
        'zero_row_const' => 0,
        'const_row' => 1,
        'single_row_lookup' => 2,
        'covering_index_lookup' => 3,
        'fulltext_index' => 3,
        'index_lookup' => 4,
        'index_range_scan' => 5,
        'index_scan' => 6,
        'table_scan' => 7,
    ];

    /**
     * Map internal access type names to MySQL standard EXPLAIN type names.
     *
     * @var array<string, string>
     */
    private const MYSQL_ACCESS_TYPE_MAP = [
        'zero_row_const' => 'const',
        'const_row' => 'const',
        'single_row_lookup' => 'eq_ref',
        'covering_index_lookup' => 'ref',
        'index_lookup' => 'ref',
        'fulltext_index' => 'fulltext',
        'index_range_scan' => 'range',
        'index_scan' => 'index',
        'table_scan' => 'ALL',
    ];

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

        // Access-type-driven detection (no regex heuristics)
        $primaryAccessType = $this->determinePrimaryAccessType($allNodes);
        $mysqlAccessType = self::MYSQL_ACCESS_TYPE_MAP[$primaryAccessType] ?? 'unknown';
        $isZeroRowConst = $this->detectZeroRowConst($allNodes);
        $isIndexBacked = $this->isIndexBacked($allNodes);
        $hasTableScan = $this->detectTableScan($allNodes);
        $hasCoveringIndex = $this->detectCoveringIndex($allNodes, $rawPlan);

        // Anti-pattern detection from plan tree
        $hasTempTable = $this->detectPattern($rawPlan, '/temporary|Temp table|Using temporary/i');
        $hasFilesort = $this->detectPattern($rawPlan, '/filesort|\bSort:/i');
        $hasWeedout = $this->detectPattern($rawPlan, '/weedout/i');
        $hasIndexMerge = $this->detectPattern($rawPlan, '/index_merge|Index merge/i');
        $hasDiskTemp = $this->detectPattern($rawPlan, '/on disk|temporary table.*disk/i');
        $hasMaterialization = $this->detectPattern($rawPlan, '/Materialize/i');
        $hasEarlyTermination = $this->detectEarlyTermination($root, $allNodes);

        // Parsing validation: actual metrics must be present on root node
        $parsingValid = $root->actualTimeEnd !== null;

        $indexesUsed = $this->collectIndexes($allNodes);
        $tablesAccessed = $this->collectTables($allNodes);

        // Complexity derived from access type (not heuristics)
        $complexity = $this->classifyComplexity(
            $primaryAccessType, $hasTableScan, $hasTempTable,
            $hasFilesort, $maxLoops, $nestedLoopDepth,
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
            'is_zero_row_const' => $isZeroRowConst,
            'primary_access_type' => $primaryAccessType,
            'mysql_access_type' => $mysqlAccessType,
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
            'parsing_valid' => $parsingValid,
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
     * Determine the primary (worst) access type across all I/O nodes.
     *
     * For single-table queries, this is the table's access type.
     * For joins, this is the worst access type across all tables
     * (the performance bottleneck).
     *
     * @param  PlanNode[]  $nodes
     */
    private function determinePrimaryAccessType(array $nodes): ?string
    {
        $worst = null;
        $worstSeverity = -1;

        foreach ($nodes as $node) {
            if ($node->accessType === null) {
                continue;
            }

            $severity = self::ACCESS_TYPE_SEVERITY[$node->accessType] ?? -1;
            if ($severity > $worstSeverity) {
                $worstSeverity = $severity;
                $worst = $node->accessType;
            }
        }

        return $worst;
    }

    /**
     * Detect whether any node represents a zero-row const table lookup.
     *
     * @param  PlanNode[]  $nodes
     */
    private function detectZeroRowConst(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if ($node->accessType === 'zero_row_const') {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if query is backed by an index.
     *
     * Index Used = TRUE if any access type is not table_scan (ALL).
     * Derived from parsed plan tree nodes, not regex.
     *
     * @param  PlanNode[]  $nodes
     */
    private function isIndexBacked(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if ($node->accessType === null) {
                continue;
            }

            if (in_array($node->accessType, [
                'index_lookup',
                'index_range_scan',
                'covering_index_lookup',
                'single_row_lookup',
                'index_scan',
                'fulltext_index',
                'zero_row_const',
                'const_row',
            ], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect real table scans (access type = ALL), excluding derived/temporary tables.
     *
     * Full Scan = TRUE only if access type == ALL (table_scan).
     *
     * @param  PlanNode[]  $nodes
     */
    private function detectTableScan(array $nodes): bool
    {
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

    /**
     * Detect covering index usage from node access types.
     *
     * Covering Index = TRUE if "covering_index_lookup" access type found
     * or "Covering index" appears in plan text.
     *
     * @param  PlanNode[]  $nodes
     */
    private function detectCoveringIndex(array $nodes, string $rawPlan): bool
    {
        foreach ($nodes as $node) {
            if ($node->accessType === 'covering_index_lookup') {
                return true;
            }
        }

        return (bool) preg_match('/Covering index/i', $rawPlan);
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
     * Classify query complexity from access type and plan modifiers.
     *
     * Primary driver: access type (const → O(1), ref → O(log n), ALL → O(n)).
     * Modifiers: nested loops (multiplicative), filesort (O(n log n)), temp table (O(n)).
     *
     * Quadratic only if: nested loops with full scans.
     */
    private function classifyComplexity(
        ?string $primaryAccessType,
        bool $hasTableScan,
        bool $hasTempTable,
        bool $hasFilesort,
        int $maxLoops,
        int $nestedLoopDepth,
    ): ComplexityClass {
        // Quadratic: nested loop with full table scans
        if ($hasTableScan && $nestedLoopDepth > 0) {
            return ComplexityClass::Quadratic;
        }
        if ($hasTableScan && $maxLoops > 10_000) {
            return ComplexityClass::Quadratic;
        }
        if ($nestedLoopDepth > 3 && $maxLoops > 1_000) {
            return ComplexityClass::Quadratic;
        }

        // Base complexity from access type
        $baseComplexity = match ($primaryAccessType) {
            'zero_row_const', 'const_row', 'single_row_lookup' => ComplexityClass::Constant,
            'covering_index_lookup', 'fulltext_index', 'index_lookup' => ComplexityClass::Logarithmic,
            'index_range_scan' => ComplexityClass::LogRange,
            'index_scan' => ComplexityClass::Linear,
            'table_scan' => ComplexityClass::Linear,
            default => ComplexityClass::Linear,
        };

        // Filesort adds O(n log n) — dominates if base complexity is lower
        if ($hasFilesort && $baseComplexity->ordinal() < ComplexityClass::Linearithmic->ordinal()) {
            return ComplexityClass::Linearithmic;
        }

        // Temp table adds O(n) — raises to at least Linear
        if ($hasTempTable && $baseComplexity->ordinal() < ComplexityClass::Linear->ordinal()) {
            return ComplexityClass::Linear;
        }

        // Nested loops multiply complexity: outer × inner
        if ($nestedLoopDepth > 0 && $baseComplexity->ordinal() < ComplexityClass::Linear->ordinal()) {
            // Nested loops with index lookups: O(n * log n) per join level
            if ($nestedLoopDepth >= 2) {
                return ComplexityClass::Linearithmic;
            }
        }

        return $baseComplexity;
    }

    /**
     * Calculate the maximum fanout factor across all join levels.
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
            'is_zero_row_const' => false,
            'primary_access_type' => null,
            'mysql_access_type' => 'unknown',
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
            'parsing_valid' => false,
        ];
    }
}
