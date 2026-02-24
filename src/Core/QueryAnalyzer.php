<?php

declare(strict_types=1);

namespace QuerySentinel\Core;

use QuerySentinel\Analyzers\ScalabilityEstimator;
use QuerySentinel\Contracts\AnalyzerInterface;
use QuerySentinel\Contracts\DriverInterface;
use QuerySentinel\Contracts\PlanParserInterface;
use QuerySentinel\Contracts\RuleRegistryInterface;
use QuerySentinel\Contracts\ScoringEngineInterface;
use QuerySentinel\Enums\ComplexityClass;
use QuerySentinel\Support\EngineConsistencyValidator;
use QuerySentinel\Support\ExplainGuard;
use QuerySentinel\Support\SqlParser;
use QuerySentinel\Support\Report;
use QuerySentinel\Support\Result;
use QuerySentinel\Validation\ValidationPipeline;

/**
 * Core query analysis pipeline. Framework-agnostic.
 *
 * FAIL-SAFE: Validation runs first. No analysis without valid SQL/schema/EXPLAIN.
 *
 * Pipeline:
 *   1. ValidationPipeline: tables, columns, joins, syntax
 *   2. ExplainGuard: EXPLAIN ANALYZE (throws on failure)
 *   3. Parser extracts metrics from plan
 *   4. Enrichment + consistency validation
 *   5. EngineConsistencyValidator: abort if access_type=UNKNOWN or plan invalid
 *   6. Scoring, rules, scalability
 *   7. Report
 */
final class QueryAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly DriverInterface $driver,
        private readonly PlanParserInterface $parser,
        private readonly ScoringEngineInterface $scoringEngine,
        private readonly RuleRegistryInterface $ruleRegistry,
        private readonly ScalabilityEstimator $scalabilityEstimator = new ScalabilityEstimator,
        private readonly ?string $connection = null,
    ) {}

    /**
     * Analyze a SQL query and produce a complete diagnostic report.
     *
     * @throws \QuerySentinel\Exceptions\EngineAbortException When validation or EXPLAIN fails (strict mode)
     */
    public function analyze(string $sql, string $mode = 'sql'): Report
    {
        $conn = $this->connection ?? config('query-diagnostics.connection');
        $strict = config('query-diagnostics.validation.strict', true);

        if ($strict) {
            // 1. Mandatory pre-execution validation
            $pipeline = new ValidationPipeline($conn);
            $pipeline->validate($sql);

            // 2. EXPLAIN ANALYZE (hard guard — throws on any failure)
            $explainGuard = new ExplainGuard($this->driver);
            $plan = $explainGuard->runExplainAnalyze($sql);
            $explainRows = $explainGuard->runExplain($sql);
        } else {
            // Legacy: direct driver calls (for SQLite tests, etc.)
            $plan = $this->driver->runExplainAnalyze($sql);
            $explainRows = $this->driver->runExplain($sql);
        }
        $metrics = $this->parser->parse($plan);

        // Enrich metrics from tabular EXPLAIN data (secondary source)
        $metrics = $this->enrichMetricsFromExplain($metrics, $explainRows);

        // Self-validate consistency — auto-correct contradictions
        $metrics = $this->validateConsistency($metrics);

        // Detect intentional full scan (no filtering/joining/grouping/ordering)
        if (($metrics['has_table_scan'] ?? false) && SqlParser::isIntentionalFullScan($sql)) {
            $metrics['is_intentional_scan'] = true;
        }

        // Enrich early termination from SQL structure (complements plan-based detection)
        if (! ($metrics['has_early_termination'] ?? false)) {
            if (SqlParser::hasLimit($sql) || SqlParser::hasExists($sql) || SqlParser::hasAggregationWithoutGroupBy($sql)) {
                $metrics['has_early_termination'] = true;
            }
        }

        // 3. Engine consistency: never report without valid plan (strict mode only)
        if ($strict) {
            $consistency = new EngineConsistencyValidator;
            $consistency->validateBeforeReport($plan, $metrics, true, true);
        }

        $scores = $this->scoringEngine->score($metrics);
        $findings = $this->evaluateRules($metrics);

        $drivingTableRows = $this->extractDrivingTableRows($explainRows);
        $scalability = $this->scalabilityEstimator->estimate($metrics, $drivingTableRows, [1_000_000, 10_000_000], $sql);

        $result = new Result(
            sql: $sql,
            driver: $this->driver->getName(),
            explainRows: $explainRows,
            plan: $plan,
            metrics: $metrics,
            scores: $scores,
            findings: $findings,
            executionTimeMs: $metrics['execution_time_ms'] ?? 0.0,
        );

        $grade = $scores['grade'];
        $passed = ! $this->hasCriticalFindings($findings);

        return new Report(
            result: $result,
            grade: $grade,
            passed: $passed,
            summary: $this->generateSummary($metrics, $scores, $findings),
            recommendations: $this->extractRecommendations($findings),
            compositeScore: $scores['composite_score'],
            analyzedAt: new \DateTimeImmutable,
            scalability: $scalability,
            mode: $mode,
        );
    }

    public function getDriver(): DriverInterface
    {
        return $this->driver;
    }

    public function getParser(): PlanParserInterface
    {
        return $this->parser;
    }

    public function getScoringEngine(): ScoringEngineInterface
    {
        return $this->scoringEngine;
    }

    public function getRuleRegistry(): RuleRegistryInterface
    {
        return $this->ruleRegistry;
    }

    /**
     * Enrich metrics from tabular EXPLAIN data.
     *
     * Uses EXPLAIN tabular output as a secondary validation/enrichment source.
     * The primary source of truth remains the EXPLAIN ANALYZE tree.
     *
     * @param  array<string, mixed>  $metrics
     * @param  array<int, array<string, mixed>>  $explainRows
     * @return array<string, mixed>
     */
    private function enrichMetricsFromExplain(array $metrics, array $explainRows): array
    {
        if (empty($explainRows)) {
            // Check if Extra contains "no matching row in const table" (zero-row const fallback)
            return $metrics;
        }

        foreach ($explainRows as $row) {
            $type = $row['type'] ?? null;
            $key = $row['key'] ?? null;
            $extra = $row['Extra'] ?? '';

            // If EXPLAIN says type=const but tree parser didn't detect it
            if ($type === 'const' && $metrics['primary_access_type'] === null) {
                $metrics['primary_access_type'] = 'const_row';
                $metrics['mysql_access_type'] = 'const';
                $metrics['is_index_backed'] = true;
                $metrics['complexity'] = ComplexityClass::Constant->value;
                $metrics['complexity_label'] = ComplexityClass::Constant->label();
                $metrics['complexity_risk'] = ComplexityClass::Constant->riskLevel();
            }

            // If EXPLAIN has a key but tree parser found no index
            if ($key !== null && empty($metrics['indexes_used'])) {
                $metrics['indexes_used'] = [$key];
                $metrics['is_index_backed'] = true;
            }

            // Detect "no matching row in const table" from Extra
            if (is_string($extra) && str_contains(strtolower($extra), 'no matching row in const table')) {
                $metrics['is_zero_row_const'] = true;
                $metrics['primary_access_type'] = $metrics['primary_access_type'] ?? 'zero_row_const';
                $metrics['mysql_access_type'] = 'const';
                $metrics['is_index_backed'] = true;
                $metrics['complexity'] = ComplexityClass::Constant->value;
                $metrics['complexity_label'] = ComplexityClass::Constant->label();
                $metrics['complexity_risk'] = ComplexityClass::Constant->riskLevel();
            }

            // Detect "Using index" for covering index
            if (is_string($extra) && str_contains($extra, 'Using index')) {
                $metrics['has_covering_index'] = true;
            }

            // If EXPLAIN type is available but parser didn't detect access type,
            // map EXPLAIN type to our internal type as fallback
            if ($metrics['primary_access_type'] === null && $type !== null) {
                $metrics['mysql_access_type'] = $type;
                $metrics['primary_access_type'] = match ($type) {
                    'const', 'system' => 'const_row',
                    'eq_ref' => 'single_row_lookup',
                    'ref', 'ref_or_null' => 'index_lookup',
                    'range' => 'index_range_scan',
                    'index' => 'index_scan',
                    'ALL' => 'table_scan',
                    default => null,
                };

                // Re-derive complexity from the newly determined access type
                if ($metrics['primary_access_type'] !== null) {
                    $complexity = match ($metrics['primary_access_type']) {
                        'const_row', 'single_row_lookup' => ComplexityClass::Constant,
                        'index_lookup' => ComplexityClass::Logarithmic,
                        'index_range_scan' => ComplexityClass::LogRange,
                        default => ComplexityClass::Linear,
                    };
                    $metrics['complexity'] = $complexity->value;
                    $metrics['complexity_label'] = $complexity->label();
                    $metrics['complexity_risk'] = $complexity->riskLevel();

                    if ($type !== 'ALL') {
                        $metrics['is_index_backed'] = true;
                    }
                }
            }
        }

        return $metrics;
    }

    /**
     * Internal consistency validation layer.
     *
     * Detects and auto-corrects contradictory metric states:
     *   - Index Used = NO but access type != ALL → correct to index backed
     *   - Rows examined = 0 and rows returned = 0 → ensure complexity is Constant or LOW risk
     *   - Zero-row const → force O(1) and LOW risk
     *
     * @param  array<string, mixed>  $metrics
     * @return array<string, mixed>
     */
    private function validateConsistency(array $metrics): array
    {
        $accessType = $metrics['primary_access_type'] ?? null;
        $isIndexBacked = $metrics['is_index_backed'] ?? false;
        $mysqlAccessType = $metrics['mysql_access_type'] ?? 'unknown';

        // Rule: If access type is NOT ALL/table_scan and NOT null, index must be marked as used
        if ($accessType !== null && $accessType !== 'table_scan' && ! $isIndexBacked) {
            $metrics['is_index_backed'] = true;
        }

        // Rule: Zero-row const must be O(1) with LOW risk
        if ($metrics['is_zero_row_const'] ?? false) {
            $metrics['complexity'] = ComplexityClass::Constant->value;
            $metrics['complexity_label'] = ComplexityClass::Constant->label();
            $metrics['complexity_risk'] = ComplexityClass::Constant->riskLevel();
            $metrics['is_index_backed'] = true;
        }

        // Rule: Rows examined = 0 AND rows returned = 0 → should not be high complexity
        $rowsExamined = $metrics['rows_examined'] ?? 0;
        $rowsReturned = $metrics['rows_returned'] ?? 0;
        if ($rowsExamined === 0 && $rowsReturned === 0 && ! ($metrics['has_table_scan'] ?? false)) {
            $currentComplexity = ComplexityClass::tryFrom($metrics['complexity'] ?? '');
            if ($currentComplexity !== null && $currentComplexity->ordinal() > ComplexityClass::Constant->ordinal()) {
                $metrics['complexity'] = ComplexityClass::Constant->value;
                $metrics['complexity_label'] = ComplexityClass::Constant->label();
                $metrics['complexity_risk'] = ComplexityClass::Constant->riskLevel();
            }
        }

        return $metrics;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function evaluateRules(array $metrics): array
    {
        $findings = [];

        foreach ($this->ruleRegistry->getRules() as $rule) {
            $finding = $rule->evaluate($metrics);

            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * @param  array<int, array<string, mixed>>  $findings
     */
    private function hasCriticalFindings(array $findings): bool
    {
        foreach ($findings as $finding) {
            if (($finding['severity'] ?? '') === 'critical') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $explainRows
     */
    private function extractDrivingTableRows(array $explainRows): int
    {
        if (empty($explainRows)) {
            return 1;
        }

        foreach ($explainRows as $row) {
            $table = $row['table'] ?? '';
            if (! str_starts_with($table, '<')) {
                return max((int) ($row['rows'] ?? 1), 1);
            }
        }

        return max((int) ($explainRows[0]['rows'] ?? 1), 1);
    }

    private function generateSummary(array $metrics, array $scores, array $findings): string
    {
        $grade = $scores['grade'] ?? 'N/A';
        $timeMs = $metrics['execution_time_ms'] ?? 0.0;
        $rowsExamined = $metrics['rows_examined'] ?? 0;
        $complexity = $metrics['complexity_label'] ?? 'Unknown';

        $criticalCount = 0;
        $warningCount = 0;

        foreach ($findings as $finding) {
            $severity = $finding['severity'] ?? '';
            if ($severity === 'critical') {
                $criticalCount++;
            }
            if ($severity === 'warning') {
                $warningCount++;
            }
        }

        $parts = [];
        $parts[] = sprintf('Grade %s', $grade);
        $parts[] = sprintf('%.2fms execution', $timeMs);
        $parts[] = sprintf('%s rows examined', number_format($rowsExamined));
        $parts[] = $complexity;

        if ($criticalCount > 0) {
            $parts[] = sprintf('%d critical issue(s)', $criticalCount);
        }
        if ($warningCount > 0) {
            $parts[] = sprintf('%d warning(s)', $warningCount);
        }
        if ($criticalCount === 0 && $warningCount === 0) {
            $parts[] = 'no issues found';
        }

        return implode(' | ', $parts);
    }

    /**
     * @param  array<int, array<string, mixed>>  $findings
     * @return array<int, string>
     */
    private function extractRecommendations(array $findings): array
    {
        $recommendations = [];

        foreach ($findings as $finding) {
            $rec = $finding['recommendation'] ?? null;
            if ($rec !== null && $rec !== '' && ! in_array($rec, $recommendations, true)) {
                $recommendations[] = $rec;
            }
        }

        return $recommendations;
    }
}
