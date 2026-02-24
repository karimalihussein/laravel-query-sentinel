<?php

declare(strict_types=1);

namespace QuerySentinel\Analyzers;

use QuerySentinel\Enums\Severity;
use QuerySentinel\Support\Finding;
use QuerySentinel\Support\SqlParser;

/**
 * Phase 4: Index Synthesis Engine.
 *
 * Recommends optimal composite indexes based on query structure, existing
 * indexes, and access patterns using the ERS (Equality, Range, Sort) principle.
 */
final class IndexSynthesisAnalyzer
{
    private int $maxRecommendations;

    private int $maxColumnsPerIndex;

    public function __construct(int $maxRecommendations = 3, int $maxColumnsPerIndex = 5)
    {
        $this->maxRecommendations = $maxRecommendations;
        $this->maxColumnsPerIndex = $maxColumnsPerIndex;
    }

    /**
     * @param  string  $sql  Raw SQL query
     * @param  array<string, mixed>  $metrics  From MetricsExtractor
     * @param  array<string, array<string, mixed>>|null  $indexAnalysis  From IndexCardinalityAnalyzer
     * @param  array<string, mixed>|null  $cardinalityDrift  From CardinalityDriftAnalyzer
     * @return array{index_synthesis: array<string, mixed>, findings: Finding[]}
     */
    public function analyze(string $sql, array $metrics, ?array $indexAnalysis, ?array $cardinalityDrift): array
    {
        // Suppress all index recommendations for optimal access types.
        // CONST/EQ_REF = unique/primary key lookup — already optimal, no index needed.
        $accessType = $metrics['primary_access_type'] ?? null;
        if (in_array($accessType, ['zero_row_const', 'const_row', 'single_row_lookup'], true)
            || ($metrics['is_intentional_scan'] ?? false)) {
            return [
                'index_synthesis' => [
                    'recommendations' => [],
                    'existing_index_assessment' => [],
                ],
                'findings' => [],
            ];
        }

        $findings = [];
        $recommendations = [];
        $existingIndexAssessment = [];

        $primaryTable = SqlParser::detectPrimaryTable($sql);
        $whereOperators = $this->classifyWhereOperators($sql);
        $joinColumns = SqlParser::extractJoinColumns($sql);
        $orderByColumns = SqlParser::extractOrderByColumns($sql);
        $selectColumns = SqlParser::extractSelectColumns($sql);
        $isSelectStar = SqlParser::isSelectStar($sql);

        // Group columns by table
        $perTableColumns = $this->groupColumnsByTable(
            $primaryTable,
            $whereOperators,
            $joinColumns,
            $orderByColumns,
            $selectColumns,
            $isSelectStar
        );

        // Build recommendations per table
        foreach ($perTableColumns as $table => $columns) {
            $indexColumns = $this->buildErsIndex($columns);

            if ($indexColumns === []) {
                continue;
            }

            // Cap columns per index
            $indexColumns = array_slice($indexColumns, 0, $this->maxColumnsPerIndex);

            // Check for overlaps with existing indexes
            $overlaps = $this->findOverlappingIndexes($table, $indexColumns, $indexAnalysis);

            // Determine index type
            $type = $this->determineIndexType($columns, $isSelectStar);

            // Score the recommendation
            $estimatedImprovement = $this->estimateImprovement(
                $table,
                $indexColumns,
                $overlaps,
                $indexAnalysis,
                $cardinalityDrift,
                $metrics
            );

            // Generate rationale
            $rationale = $this->buildRationale($columns, $type, $overlaps);

            // Generate DDL
            $ddl = $this->generateDdl($table, $indexColumns, $type);

            $recommendations[] = [
                'table' => $table,
                'columns' => $indexColumns,
                'type' => $type,
                'estimated_improvement' => $estimatedImprovement,
                'ddl' => $ddl,
                'rationale' => $rationale,
                'overlaps_with' => $overlaps,
            ];
        }

        // Sort by estimated improvement (high > medium > low)
        usort($recommendations, function (array $a, array $b): int {
            $order = ['high' => 0, 'medium' => 1, 'low' => 2];

            return ($order[$a['estimated_improvement']] ?? 3) <=> ($order[$b['estimated_improvement']] ?? 3);
        });

        // Limit recommendations
        $recommendations = array_slice($recommendations, 0, $this->maxRecommendations);

        // Assess existing indexes
        if ($indexAnalysis !== null) {
            $existingIndexAssessment = $this->assessExistingIndexes(
                $indexAnalysis,
                $whereOperators,
                $joinColumns
            );
        }

        // Generate findings
        foreach ($recommendations as $rec) {
            $severity = match ($rec['estimated_improvement']) {
                'high' => Severity::Warning,
                'medium' => Severity::Optimization,
                default => Severity::Info,
            };

            $findings[] = new Finding(
                severity: $severity,
                category: 'index_synthesis',
                title: sprintf('Missing index on `%s`', $rec['table']),
                description: sprintf(
                    'Recommend %s index on columns (%s) for improved query performance.',
                    $rec['type'],
                    implode(', ', $rec['columns'])
                ),
                recommendation: $rec['ddl'],
                metadata: [
                    'table' => $rec['table'],
                    'columns' => $rec['columns'],
                    'type' => $rec['type'],
                    'estimated_improvement' => $rec['estimated_improvement'],
                    'overlaps_with' => $rec['overlaps_with'],
                ],
            );
        }

        // Findings for suboptimal/redundant existing indexes
        foreach ($existingIndexAssessment as $assessment) {
            if ($assessment['status'] === 'suboptimal') {
                $findings[] = new Finding(
                    severity: Severity::Optimization,
                    category: 'index_synthesis',
                    title: sprintf('Suboptimal index `%s`', $assessment['index']),
                    description: $assessment['reason'],
                    recommendation: 'Consider reordering index columns to match query WHERE clause.',
                    metadata: ['index' => $assessment['index'], 'status' => $assessment['status']],
                );
            } elseif ($assessment['status'] === 'redundant') {
                $findings[] = new Finding(
                    severity: Severity::Optimization,
                    category: 'index_synthesis',
                    title: sprintf('Redundant index `%s`', $assessment['index']),
                    description: $assessment['reason'],
                    recommendation: sprintf('DROP INDEX `%s`;', $assessment['index']),
                    metadata: ['index' => $assessment['index'], 'status' => $assessment['status']],
                );
            }
        }

        return [
            'index_synthesis' => [
                'recommendations' => $recommendations,
                'existing_index_assessment' => $existingIndexAssessment,
            ],
            'findings' => $findings,
        ];
    }

    /**
     * Classify WHERE columns into equality and range operators by inspecting the raw SQL.
     *
     * @return array{equality: string[], range: string[]}
     */
    private function classifyWhereOperators(string $sql): array
    {
        $equality = [];
        $range = [];

        if (! preg_match('/\bWHERE\b(.+?)(?:\bORDER\s+BY\b|\bGROUP\s+BY\b|\bHAVING\b|\bLIMIT\b|\bUNION\b|$)/is', $sql, $whereMatch)) {
            return ['equality' => [], 'range' => []];
        }

        $whereClause = $whereMatch[1];

        // Match column = value (equality)
        if (preg_match_all('/(`?\w+`?\.)?`?(\w+)`?\s*(?:=)\s*(?!=)/i', $whereClause, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $table = trim($match[1], '`.') ?: null;
                $column = trim($match[2], '`');
                $full = $table ? "{$table}.{$column}" : $column;

                // Exclude SQL keywords
                if (in_array(strtoupper($column), ['AND', 'OR', 'ON', 'NULL', 'IS', 'NOT', 'TRUE', 'FALSE', 'SELECT', 'FROM', 'WHERE'], true)) {
                    continue;
                }

                $equality[] = $full;
            }
        }

        // Match range operators: >, <, >=, <=, BETWEEN, IN
        if (preg_match_all('/(`?\w+`?\.)?`?(\w+)`?\s*(?:>=|<=|<>|!=|>|<|BETWEEN\b|\bIN\s*\()/i', $whereClause, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $table = trim($match[1], '`.') ?: null;
                $column = trim($match[2], '`');
                $full = $table ? "{$table}.{$column}" : $column;

                if (in_array(strtoupper($column), ['AND', 'OR', 'ON', 'NULL', 'IS', 'NOT', 'TRUE', 'FALSE', 'SELECT', 'FROM', 'WHERE'], true)) {
                    continue;
                }

                // Remove from equality if it was also matched there (e.g., >= matches =)
                $equality = array_values(array_filter($equality, fn (string $c): bool => $c !== $full));
                $range[] = $full;
            }
        }

        return [
            'equality' => array_values(array_unique($equality)),
            'range' => array_values(array_unique($range)),
        ];
    }

    /**
     * Group all candidate columns by their table.
     *
     * @param  array{equality: string[], range: string[]}  $whereOperators
     * @param  string[]  $joinColumns
     * @param  string[]  $orderByColumns
     * @param  string[]  $selectColumns
     * @return array<string, array{equality: string[], range: string[], join: string[], order_by: string[], select: string[]}>
     */
    private function groupColumnsByTable(
        string $primaryTable,
        array $whereOperators,
        array $joinColumns,
        array $orderByColumns,
        array $selectColumns,
        bool $isSelectStar
    ): array {
        /** @var array<string, array{equality: string[], range: string[], join: string[], order_by: string[], select: string[]}> $perTable */
        $perTable = [];

        $ensureTable = function (string $table) use (&$perTable): void {
            if (! isset($perTable[$table])) {
                $perTable[$table] = [
                    'equality' => [],
                    'range' => [],
                    'join' => [],
                    'order_by' => [],
                    'select' => [],
                ];
            }
        };

        // Equality columns
        foreach ($whereOperators['equality'] as $col) {
            [$table, $column] = $this->resolveTableColumn($col, $primaryTable);
            $ensureTable($table);
            if (! in_array($column, $perTable[$table]['equality'], true)) {
                $perTable[$table]['equality'][] = $column;
            }
        }

        // Range columns
        foreach ($whereOperators['range'] as $col) {
            [$table, $column] = $this->resolveTableColumn($col, $primaryTable);
            $ensureTable($table);
            if (! in_array($column, $perTable[$table]['range'], true)) {
                $perTable[$table]['range'][] = $column;
            }
        }

        // Join columns
        foreach ($joinColumns as $col) {
            [$table, $column] = $this->resolveTableColumn($col, $primaryTable);
            $ensureTable($table);
            if (! in_array($column, $perTable[$table]['join'], true)) {
                $perTable[$table]['join'][] = $column;
            }
        }

        // ORDER BY columns (attribute to primary table)
        foreach ($orderByColumns as $orderSpec) {
            $colName = preg_replace('/\s+(ASC|DESC)$/i', '', trim($orderSpec)) ?? '';
            [$table, $column] = $this->resolveTableColumn($colName, $primaryTable);
            $ensureTable($table);
            if (! in_array($column, $perTable[$table]['order_by'], true)) {
                $perTable[$table]['order_by'][] = $column;
            }
        }

        // SELECT columns (only for covering index extension, skip *)
        if (! $isSelectStar) {
            foreach ($selectColumns as $col) {
                if ($col === '*') {
                    continue;
                }
                // Strip aliases and functions
                $cleanCol = $this->cleanSelectColumn($col);
                if ($cleanCol === null) {
                    continue;
                }
                [$table, $column] = $this->resolveTableColumn($cleanCol, $primaryTable);
                $ensureTable($table);
                if (! in_array($column, $perTable[$table]['select'], true)) {
                    $perTable[$table]['select'][] = $column;
                }
            }
        }

        return $perTable;
    }

    /**
     * Resolve a potentially table-qualified column name into [table, column].
     *
     * @return array{0: string, 1: string}
     */
    private function resolveTableColumn(string $col, string $primaryTable): array
    {
        $parts = explode('.', $col);

        if (count($parts) === 2) {
            return [trim($parts[0], '`'), trim($parts[1], '`')];
        }

        return [$primaryTable, trim($parts[0], '`')];
    }

    /**
     * Clean a SELECT column, removing aliases and functions.
     */
    private function cleanSelectColumn(string $col): ?string
    {
        // Remove alias (AS keyword or implicit)
        $col = preg_replace('/\s+(?:AS\s+)?\w+\s*$/i', '', trim($col)) ?? trim($col);

        // Skip aggregate functions
        if (preg_match('/^\s*(COUNT|SUM|AVG|MIN|MAX|GROUP_CONCAT)\s*\(/i', $col)) {
            return null;
        }

        // Skip expressions with parentheses
        if (str_contains($col, '(')) {
            return null;
        }

        $col = trim($col);

        return $col !== '' ? $col : null;
    }

    /**
     * Build an ERS-ordered index: equality columns, then range, then order_by, then select (covering).
     *
     * @param  array{equality: string[], range: string[], join: string[], order_by: string[], select: string[]}  $columns
     * @return string[]
     */
    private function buildErsIndex(array $columns): array
    {
        $index = [];
        $seen = [];

        // 1. Equality columns first
        foreach ($columns['equality'] as $col) {
            if (! isset($seen[$col])) {
                $index[] = $col;
                $seen[$col] = true;
            }
        }

        // 2. Join columns (treated similarly to equality for index purposes)
        foreach ($columns['join'] as $col) {
            if (! isset($seen[$col])) {
                $index[] = $col;
                $seen[$col] = true;
            }
        }

        // 3. Range columns
        foreach ($columns['range'] as $col) {
            if (! isset($seen[$col])) {
                $index[] = $col;
                $seen[$col] = true;
            }
        }

        // 4. ORDER BY columns
        foreach ($columns['order_by'] as $col) {
            if (! isset($seen[$col])) {
                $index[] = $col;
                $seen[$col] = true;
            }
        }

        // 5. SELECT columns (covering index extension)
        foreach ($columns['select'] as $col) {
            if (! isset($seen[$col])) {
                $index[] = $col;
                $seen[$col] = true;
            }
        }

        return $index;
    }

    /**
     * Determine the type of index recommendation.
     *
     * @param  array{equality: string[], range: string[], join: string[], order_by: string[], select: string[]}  $columns
     */
    private function determineIndexType(array $columns, bool $isSelectStar): string
    {
        $hasSelect = ! $isSelectStar && $columns['select'] !== [];
        $hasMultipleSources = (
            (int) ($columns['equality'] !== [])
            + (int) ($columns['range'] !== [])
            + (int) ($columns['join'] !== [])
            + (int) ($columns['order_by'] !== [])
        ) > 1;

        if ($hasSelect) {
            return 'covering';
        }

        if ($hasMultipleSources) {
            return 'composite';
        }

        return 'partial';
    }

    /**
     * Find existing indexes that overlap with the recommended index columns.
     *
     * @param  string[]  $indexColumns
     * @param  array<string, array<string, mixed>>|null  $indexAnalysis
     * @return string[]
     */
    private function findOverlappingIndexes(string $table, array $indexColumns, ?array $indexAnalysis): array
    {
        $overlaps = [];

        if ($indexAnalysis === null || ! isset($indexAnalysis[$table])) {
            return $overlaps;
        }

        $tableIndexes = $indexAnalysis[$table];

        foreach ($tableIndexes as $indexName => $indexData) {
            if (! is_array($indexData) || ! isset($indexData['columns'])) {
                continue;
            }

            $existingColumns = $this->extractExistingIndexColumns($indexData['columns']);

            // Check if the existing index is a prefix of or overlaps with the recommended index
            if ($this->indexColumnsOverlap($existingColumns, $indexColumns)) {
                $overlaps[] = (string) $indexName;
            }
        }

        return $overlaps;
    }

    /**
     * Extract column names from the index data columns structure.
     *
     * @param  mixed  $columns
     * @return string[]
     */
    private function extractExistingIndexColumns(mixed $columns): array
    {
        if (! is_array($columns)) {
            return [];
        }

        $result = [];
        // Sort by sequence to ensure correct order
        ksort($columns);
        foreach ($columns as $colData) {
            if (is_array($colData) && isset($colData['column'])) {
                $result[] = (string) $colData['column'];
            } elseif (is_string($colData)) {
                $result[] = $colData;
            }
        }

        return $result;
    }

    /**
     * Check if two sets of index columns overlap (one is a prefix of the other, or they share leading columns).
     *
     * @param  string[]  $existing
     * @param  string[]  $recommended
     */
    private function indexColumnsOverlap(array $existing, array $recommended): bool
    {
        if ($existing === [] || $recommended === []) {
            return false;
        }

        // Check if existing is a prefix of recommended or vice versa
        $minLen = min(count($existing), count($recommended));

        for ($i = 0; $i < $minLen; $i++) {
            if (strcasecmp($existing[$i], $recommended[$i]) !== 0) {
                return $i > 0; // Overlap if at least one leading column matches
            }
        }

        return true; // Full prefix match
    }

    /**
     * Estimate the improvement a recommended index would provide.
     *
     * @param  string[]  $indexColumns
     * @param  string[]  $overlaps
     * @param  array<string, array<string, mixed>>|null  $indexAnalysis
     * @param  array<string, mixed>|null  $cardinalityDrift
     * @param  array<string, mixed>  $metrics
     */
    private function estimateImprovement(
        string $table,
        array $indexColumns,
        array $overlaps,
        ?array $indexAnalysis,
        ?array $cardinalityDrift,
        array $metrics
    ): string {
        $rowsExamined = (int) ($metrics['rows_examined'] ?? 0);

        // Check if cardinality drift is significant
        $hasDrift = false;
        if ($cardinalityDrift !== null && isset($cardinalityDrift['per_table'][$table])) {
            $tableDrift = $cardinalityDrift['per_table'][$table];
            $driftRatio = (float) ($tableDrift['drift_ratio'] ?? 0.0);
            $hasDrift = $driftRatio > 0.5;
        }

        // Check if existing indexes cover WHERE columns
        $hasExistingCoverage = $overlaps !== [];

        if (! $hasExistingCoverage && ($hasDrift || $rowsExamined > 10000)) {
            return 'high';
        }

        if ($hasExistingCoverage && count($overlaps) < count($indexColumns)) {
            return 'medium';
        }

        if (! $hasExistingCoverage) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * Build a human-readable rationale for the recommendation.
     *
     * @param  array{equality: string[], range: string[], join: string[], order_by: string[], select: string[]}  $columns
     * @param  string[]  $overlaps
     */
    private function buildRationale(array $columns, string $type, array $overlaps): string
    {
        $parts = [];

        if ($columns['equality'] !== []) {
            $parts[] = sprintf('equality filters on (%s)', implode(', ', $columns['equality']));
        }

        if ($columns['range'] !== []) {
            $parts[] = sprintf('range filters on (%s)', implode(', ', $columns['range']));
        }

        if ($columns['join'] !== []) {
            $parts[] = sprintf('join conditions on (%s)', implode(', ', $columns['join']));
        }

        if ($columns['order_by'] !== []) {
            $parts[] = sprintf('sort on (%s)', implode(', ', $columns['order_by']));
        }

        if ($type === 'covering' && $columns['select'] !== []) {
            $parts[] = sprintf('covering (%s)', implode(', ', $columns['select']));
        }

        $rationale = 'ERS-ordered index for ' . implode(', ', $parts) . '.';

        if ($overlaps !== []) {
            $rationale .= sprintf(' Overlaps with existing: %s.', implode(', ', $overlaps));
        }

        return $rationale;
    }

    /**
     * Generate CREATE INDEX DDL.
     *
     * @param  string[]  $indexColumns
     */
    private function generateDdl(string $table, array $indexColumns, string $type): string
    {
        $suffix = match ($type) {
            'covering' => '_covering',
            'composite' => '_composite',
            default => '',
        };

        $indexName = sprintf(
            'idx_%s_%s%s',
            $table,
            implode('_', $indexColumns),
            $suffix
        );

        // Truncate overly long index names
        if (strlen($indexName) > 64) {
            $indexName = substr($indexName, 0, 64);
        }

        $columnList = implode(', ', array_map(fn (string $c): string => "`{$c}`", $indexColumns));

        return sprintf('CREATE INDEX `%s` ON `%s` (%s);', $indexName, $table, $columnList);
    }

    /**
     * Assess existing indexes as optimal, suboptimal, redundant, or unused.
     *
     * @param  array<string, array<string, mixed>>  $indexAnalysis
     * @param  array{equality: string[], range: string[]}  $whereOperators
     * @param  string[]  $joinColumns
     * @return array<int, array{index: string, status: string, reason: string}>
     */
    private function assessExistingIndexes(
        array $indexAnalysis,
        array $whereOperators,
        array $joinColumns
    ): array {
        $assessment = [];
        $allFilterColumns = array_merge($whereOperators['equality'], $whereOperators['range']);

        // Flatten join columns to bare column names
        $joinColNames = array_map(function (string $col): string {
            $parts = explode('.', $col);

            return count($parts) === 2 ? $parts[1] : $parts[0];
        }, $joinColumns);

        $allColumns = array_merge($allFilterColumns, $joinColNames);

        // Collect all indexes across tables for redundancy detection
        /** @var array<string, array{table: string, columns: string[], is_used: bool}> $allIndexes */
        $allIndexes = [];

        foreach ($indexAnalysis as $table => $indexes) {
            if (! is_array($indexes)) {
                continue;
            }

            foreach ($indexes as $indexName => $indexData) {
                if (! is_array($indexData)) {
                    continue;
                }

                $isUsed = (bool) ($indexData['is_used'] ?? false);
                $existingColumns = isset($indexData['columns'])
                    ? $this->extractExistingIndexColumns($indexData['columns'])
                    : [];

                $allIndexes[(string) $indexName] = [
                    'table' => (string) $table,
                    'columns' => $existingColumns,
                    'is_used' => $isUsed,
                ];
            }
        }

        foreach ($allIndexes as $indexName => $indexInfo) {
            $existingColumns = $indexInfo['columns'];
            $isUsed = $indexInfo['is_used'];

            if (! $isUsed) {
                $assessment[] = [
                    'index' => $indexName,
                    'status' => 'unused',
                    'reason' => sprintf('Index `%s` was not used by this query.', $indexName),
                ];

                continue;
            }

            // Check if first column is in WHERE equality columns
            $firstColumn = $existingColumns[0] ?? null;
            $firstColumnInEquality = $firstColumn !== null && $this->columnInList($firstColumn, $whereOperators['equality']);
            $firstColumnInFilters = $firstColumn !== null && $this->columnInList($firstColumn, $allColumns);

            if ($firstColumnInEquality) {
                $assessment[] = [
                    'index' => $indexName,
                    'status' => 'optimal',
                    'reason' => sprintf('Index `%s` leading column matches WHERE equality filter.', $indexName),
                ];
            } elseif ($firstColumnInFilters) {
                $assessment[] = [
                    'index' => $indexName,
                    'status' => 'suboptimal',
                    'reason' => sprintf(
                        'Index `%s` is used but leading column `%s` is not in WHERE equality conditions.',
                        $indexName,
                        $firstColumn
                    ),
                ];
            } else {
                $assessment[] = [
                    'index' => $indexName,
                    'status' => 'suboptimal',
                    'reason' => sprintf(
                        'Index `%s` is used but leading column `%s` is not in the WHERE clause.',
                        $indexName,
                        $firstColumn ?? 'unknown'
                    ),
                ];
            }
        }

        // Check for redundancy: if index A is a prefix of index B, A is redundant
        foreach ($allIndexes as $nameA => $infoA) {
            foreach ($allIndexes as $nameB => $infoB) {
                if ($nameA === $nameB || $infoA['table'] !== $infoB['table']) {
                    continue;
                }

                $colsA = $infoA['columns'];
                $colsB = $infoB['columns'];

                if ($colsA === [] || $colsB === []) {
                    continue;
                }

                // Check if A is a strict prefix of B (A is contained in B)
                if (count($colsA) < count($colsB) && array_slice($colsB, 0, count($colsA)) === $colsA) {
                    // Mark A as redundant (B covers it)
                    $alreadyRedundant = false;
                    foreach ($assessment as &$existing) {
                        if ($existing['index'] === $nameA) {
                            $existing['status'] = 'redundant';
                            $existing['reason'] = sprintf(
                                'Index `%s` is a prefix of `%s` and can be removed.',
                                $nameA,
                                $nameB
                            );
                            $alreadyRedundant = true;
                            break;
                        }
                    }
                    unset($existing);

                    if (! $alreadyRedundant) {
                        $assessment[] = [
                            'index' => $nameA,
                            'status' => 'redundant',
                            'reason' => sprintf(
                                'Index `%s` is a prefix of `%s` and can be removed.',
                                $nameA,
                                $nameB
                            ),
                        ];
                    }
                }
            }
        }

        return $assessment;
    }

    /**
     * Check if a column name appears in a list of potentially table-qualified column names.
     *
     * @param  string[]  $columnList
     */
    private function columnInList(string $column, array $columnList): bool
    {
        foreach ($columnList as $candidate) {
            $parts = explode('.', $candidate);
            $colName = count($parts) === 2 ? $parts[1] : $parts[0];

            if (strcasecmp($colName, $column) === 0) {
                return true;
            }
        }

        return false;
    }
}
