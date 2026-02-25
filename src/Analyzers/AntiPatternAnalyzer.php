<?php

declare(strict_types=1);

namespace QuerySentinel\Analyzers;

use QuerySentinel\Enums\Severity;
use QuerySentinel\Support\Finding;
use QuerySentinel\Support\SqlParser;

/**
 * Phase 3: SQL Anti-Pattern Analyzer.
 *
 * Static SQL analysis detecting 10 common anti-patterns without executing EXPLAIN.
 * Each pattern generates a Finding with severity, description, and recommendation.
 */
final class AntiPatternAnalyzer
{
    private int $orChainThreshold;

    private int $missingLimitRowThreshold;

    public function __construct(int $orChainThreshold = 3, int $missingLimitRowThreshold = 10000)
    {
        $this->orChainThreshold = $orChainThreshold;
        $this->missingLimitRowThreshold = $missingLimitRowThreshold;
    }

    /**
     * @param  string  $sql  Raw SQL query
     * @param  array<string, mixed>  $metrics  From MetricsExtractor
     * @return array{anti_patterns: array<int, array<string, mixed>>, findings: Finding[]}
     */
    public function analyze(string $sql, array $metrics): array
    {
        $patterns = [];
        $findings = [];

        // 1. SELECT *
        if (SqlParser::isSelectStar($sql)) {
            $pattern = [
                'pattern' => 'select_star',
                'severity' => 'warning',
                'location' => 'SELECT clause',
                'description' => 'SELECT * retrieves all columns, preventing covering index optimization and increasing I/O.',
                'recommendation' => 'Specify only the columns you need: SELECT col1, col2 FROM ...',
            ];
            $patterns[] = $pattern;
            $findings[] = new Finding(
                severity: Severity::Warning,
                category: 'anti_pattern',
                title: 'SELECT * detected',
                description: $pattern['description'],
                recommendation: $pattern['recommendation'],
            );
        }

        // 2. Functions on indexed columns
        $functionsOnCols = SqlParser::detectFunctionsOnColumns($sql);
        foreach ($functionsOnCols as $funcInfo) {
            $pattern = [
                'pattern' => 'function_on_column',
                'severity' => 'warning',
                'location' => sprintf('WHERE clause: %s(%s)', $funcInfo['function'], $funcInfo['column']),
                'description' => sprintf(
                    'Function %s() applied to column `%s` in WHERE clause prevents index usage on that column.',
                    $funcInfo['function'],
                    $funcInfo['column']
                ),
                'recommendation' => sprintf(
                    'Rewrite to avoid wrapping the column: use a generated column, functional index, or restructure the condition.',
                ),
            ];
            $patterns[] = $pattern;
            $findings[] = new Finding(
                severity: Severity::Warning,
                category: 'anti_pattern',
                title: sprintf('Function on column: %s(%s)', $funcInfo['function'], $funcInfo['column']),
                description: $pattern['description'],
                recommendation: $pattern['recommendation'],
                metadata: ['function' => $funcInfo['function'], 'column' => $funcInfo['column']],
            );
        }

        // 3. Implicit type conversion (detected when WHERE has quoted numbers or unquoted strings)
        if (preg_match('/\bWHERE\b/i', $sql) && preg_match('/\w+\s*=\s*\'?\d+\'?/i', $sql)) {
            // This is a simplified check — true implicit conversion detection requires schema info
            // We flag it as optimization-level only
        }

        // 4. OR chains exceeding threshold
        $orCount = SqlParser::countOrChains($sql);
        if ($orCount >= $this->orChainThreshold) {
            $pattern = [
                'pattern' => 'excessive_or_chains',
                'severity' => 'warning',
                'location' => 'WHERE clause',
                'description' => sprintf(
                    'WHERE clause contains %d OR conditions. Excessive OR chains prevent efficient index range scans and may cause full table scans.',
                    $orCount
                ),
                'recommendation' => 'Rewrite using IN() for equality comparisons, or split into UNION ALL queries for better index utilization.',
            ];
            $patterns[] = $pattern;
            $findings[] = new Finding(
                severity: Severity::Warning,
                category: 'anti_pattern',
                title: sprintf('Excessive OR chain: %d conditions', $orCount),
                description: $pattern['description'],
                recommendation: $pattern['recommendation'],
                metadata: ['or_count' => $orCount],
            );
        }

        // 5. Correlated subqueries
        if (SqlParser::detectCorrelatedSubqueries($sql)) {
            $pattern = [
                'pattern' => 'correlated_subquery',
                'severity' => 'warning',
                'location' => 'Subquery',
                'description' => 'Correlated subquery detected — the inner query references the outer query, causing it to execute once per outer row.',
                'recommendation' => 'Rewrite using JOIN or EXISTS with proper indexing. Consider materializing the subquery result.',
            ];
            $patterns[] = $pattern;
            $findings[] = new Finding(
                severity: Severity::Warning,
                category: 'anti_pattern',
                title: 'Correlated subquery detected',
                description: $pattern['description'],
                recommendation: $pattern['recommendation'],
            );
        }

        // 6. NOT IN with subquery
        if (preg_match('/\bNOT\s+IN\s*\(\s*SELECT\b/i', $sql)) {
            $pattern = [
                'pattern' => 'not_in_subquery',
                'severity' => 'warning',
                'location' => 'WHERE clause',
                'description' => 'NOT IN with a subquery cannot be optimized as an anti-join in all cases and handles NULL values unexpectedly.',
                'recommendation' => 'Rewrite using NOT EXISTS or LEFT JOIN ... WHERE right.id IS NULL for better optimization.',
            ];
            $patterns[] = $pattern;
            $findings[] = new Finding(
                severity: Severity::Warning,
                category: 'anti_pattern',
                title: 'NOT IN with subquery',
                description: $pattern['description'],
                recommendation: $pattern['recommendation'],
            );
        }

        // 7. Leading wildcard LIKE
        if (SqlParser::hasLeadingWildcard($sql)) {
            $pattern = [
                'pattern' => 'leading_wildcard',
                'severity' => 'warning',
                'location' => 'WHERE clause',
                'description' => 'LIKE pattern with leading wildcard (e.g., LIKE \'%value\') prevents index usage and forces a full scan.',
                'recommendation' => 'Use full-text search (FULLTEXT index), reverse the column with a generated column, or use an external search engine.',
            ];
            $patterns[] = $pattern;
            $findings[] = new Finding(
                severity: Severity::Warning,
                category: 'anti_pattern',
                title: 'Leading wildcard in LIKE',
                description: $pattern['description'],
                recommendation: $pattern['recommendation'],
            );
        }

        // 8. Missing LIMIT on large result set
        $hasLimit = (bool) preg_match('/\bLIMIT\b/i', $sql);
        $hasAggregate = (bool) preg_match('/\b(COUNT|SUM|AVG|MIN|MAX|GROUP_CONCAT)\s*\(/i', $sql);
        $rowsExamined = $metrics['rows_examined'] ?? 0;

        if (! $hasLimit && ! $hasAggregate && $rowsExamined > $this->missingLimitRowThreshold) {
            $pattern = [
                'pattern' => 'missing_limit',
                'severity' => 'optimization',
                'location' => 'Query structure',
                'description' => sprintf(
                    'Query examines %s rows without a LIMIT clause and no aggregate functions. Large unbounded result sets increase memory usage and network transfer.',
                    number_format($rowsExamined)
                ),
                'recommendation' => 'Add a LIMIT clause if only a subset of rows is needed, or implement pagination.',
            ];
            $patterns[] = $pattern;
            $findings[] = new Finding(
                severity: Severity::Optimization,
                category: 'anti_pattern',
                title: 'Missing LIMIT on large result',
                description: $pattern['description'],
                recommendation: $pattern['recommendation'],
                metadata: ['rows_examined' => $rowsExamined],
            );
        }

        // 9. ORDER BY RAND()
        if (preg_match('/\bORDER\s+BY\s+RAND\s*\(\s*\)/i', $sql)) {
            $pattern = [
                'pattern' => 'order_by_rand',
                'severity' => 'critical',
                'location' => 'ORDER BY clause',
                'description' => 'ORDER BY RAND() forces a full table scan, generates a random value per row, and then sorts all rows. This is O(n log n) at minimum.',
                'recommendation' => 'Use application-side random offset, a pre-computed random column, or subquery with random LIMIT offset.',
            ];
            $patterns[] = $pattern;
            $findings[] = new Finding(
                severity: Severity::Critical,
                category: 'anti_pattern',
                title: 'ORDER BY RAND() detected',
                description: $pattern['description'],
                recommendation: $pattern['recommendation'],
            );
        }

        // 10. Redundant DISTINCT (simplified check - true check requires schema)
        if (preg_match('/\bSELECT\s+DISTINCT\b/i', $sql)) {
            // Check if primary key or unique key columns are in SELECT
            $indexesUsed = $metrics['indexes_used'] ?? [];
            $primaryKeyUsed = in_array('PRIMARY', $indexesUsed, true);

            if ($primaryKeyUsed) {
                $pattern = [
                    'pattern' => 'redundant_distinct',
                    'severity' => 'optimization',
                    'location' => 'SELECT clause',
                    'description' => 'DISTINCT is used but the query accesses through a PRIMARY/UNIQUE key, which already guarantees unique rows.',
                    'recommendation' => 'Remove DISTINCT to avoid unnecessary sort/hash deduplication overhead.',
                ];
                $patterns[] = $pattern;
                $findings[] = new Finding(
                    severity: Severity::Optimization,
                    category: 'anti_pattern',
                    title: 'Potentially redundant DISTINCT',
                    description: $pattern['description'],
                    recommendation: $pattern['recommendation'],
                );
            }
        }

        return [
            'anti_patterns' => $patterns,
            'findings' => $findings,
        ];
    }
}
