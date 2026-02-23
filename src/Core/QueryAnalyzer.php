<?php

declare(strict_types=1);

namespace QuerySentinel\Core;

use QuerySentinel\Analyzers\ScalabilityEstimator;
use QuerySentinel\Contracts\AnalyzerInterface;
use QuerySentinel\Contracts\DriverInterface;
use QuerySentinel\Contracts\PlanParserInterface;
use QuerySentinel\Contracts\RuleRegistryInterface;
use QuerySentinel\Contracts\ScoringEngineInterface;
use QuerySentinel\Support\Report;
use QuerySentinel\Support\Result;

/**
 * Core query analysis pipeline. Framework-agnostic.
 *
 * Pipeline:
 *   1. Driver runs EXPLAIN ANALYZE → raw plan text
 *   2. Driver runs EXPLAIN → tabular rows
 *   3. Parser extracts structured metrics from plan
 *   4. Scoring engine calculates weighted composite score
 *   5. Rule registry evaluates all enabled rules
 *   6. Scalability estimator projects growth at target row counts
 *   7. Summary, recommendations, and pass/fail assembled into Report
 *
 * This class ONLY operates on SQL strings. Input transformation
 * is handled by adapters (SqlAdapter, BuilderAdapter, etc.).
 */
final class QueryAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly DriverInterface $driver,
        private readonly PlanParserInterface $parser,
        private readonly ScoringEngineInterface $scoringEngine,
        private readonly RuleRegistryInterface $ruleRegistry,
        private readonly ScalabilityEstimator $scalabilityEstimator = new ScalabilityEstimator,
    ) {}

    /**
     * Analyze a SQL query and produce a complete diagnostic report.
     */
    public function analyze(string $sql, string $mode = 'sql'): Report
    {
        $plan = $this->driver->runExplainAnalyze($sql);
        $explainRows = $this->driver->runExplain($sql);
        $metrics = $this->parser->parse($plan);
        $scores = $this->scoringEngine->score($metrics);
        $findings = $this->evaluateRules($metrics);

        $drivingTableRows = $this->extractDrivingTableRows($explainRows);
        $scalability = $this->scalabilityEstimator->estimate($metrics, $drivingTableRows);

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
