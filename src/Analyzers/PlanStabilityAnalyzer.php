<?php

declare(strict_types=1);

namespace QuerySentinel\Analyzers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use QuerySentinel\Enums\Severity;
use QuerySentinel\Support\Finding;

/**
 * Section 7: Plan Stability & Risk.
 *
 * Detects optimizer hints, assesses plan flip risk from estimated/actual
 * row deviations, and flags stale statistics needing ANALYZE TABLE.
 */
final class PlanStabilityAnalyzer
{
    /**
     * @param  string  $rawSql  The original SQL
     * @param  string  $plan  EXPLAIN ANALYZE output
     * @param  array<string, mixed>  $metrics  From MetricsExtractor
     * @param  array<int, array<string, mixed>>  $explainRows  From EXPLAIN tabular output
     * @return array{stability: array<string, mixed>, findings: Finding[]}
     */
    public function analyze(string $rawSql, string $plan, array $metrics, array $explainRows, ?string $connectionName = null, ?array $cardinalityDrift = null): array
    {
        $findings = [];
        $stability = [];

        $hints = $this->detectOptimizerHints($rawSql);
        $stability['optimizer_hints'] = $hints;

        if (! empty($hints)) {
            $findings[] = new Finding(
                severity: Severity::Info,
                category: 'plan_stability',
                title: sprintf('Optimizer hints detected: %s', implode(', ', $hints)),
                description: 'The query uses explicit optimizer hints which lock the execution plan. This prevents the optimizer from adapting to data distribution changes.',
                recommendation: 'Periodically review whether hints are still optimal as data grows.',
                metadata: ['hints' => $hints],
            );
        }

        $flipRisk = $this->assessPlanFlipRisk($plan);
        $stability['plan_flip_risk'] = $flipRisk;

        if ($flipRisk['is_risky']) {
            $findings[] = new Finding(
                severity: Severity::Warning,
                category: 'plan_stability',
                title: 'Plan flip risk detected',
                description: $flipRisk['description'],
                recommendation: 'Run ANALYZE TABLE on the affected tables to update optimizer statistics.',
                metadata: $flipRisk,
            );
        }

        $driftIssues = $this->detectStatisticsDrift($explainRows, $connectionName);
        $stability['statistics_drift'] = $driftIssues;

        foreach ($driftIssues as $issue) {
            $findings[] = new Finding(
                severity: Severity::Warning,
                category: 'plan_stability',
                title: sprintf('Stale statistics on `%s`', $issue['table']),
                description: $issue['description'],
                recommendation: sprintf('ANALYZE TABLE `%s`;', $issue['table']),
                metadata: $issue,
            );
        }

        // Volatility scoring (Phase 8)
        $volatilityScore = $this->calculateVolatilityScore($flipRisk, $hints, $cardinalityDrift);
        $volatilityLabel = $this->classifyVolatility($volatilityScore);
        $stability['volatility_score'] = $volatilityScore;
        $stability['volatility_label'] = $volatilityLabel;

        // Drift contributors
        $driftContributors = $this->extractDriftContributors($cardinalityDrift);
        $stability['drift_contributors'] = $driftContributors;

        // Generate volatility findings
        if ($volatilityLabel === 'volatile') {
            $findings[] = new Finding(
                severity: Severity::Warning,
                category: 'plan_stability',
                title: sprintf('High plan volatility: %d/100', $volatilityScore),
                description: 'The execution plan is volatile due to estimation deviations and stale statistics. The optimizer may choose different plans on repeated execution.',
                recommendation: 'Run ANALYZE TABLE on affected tables and consider using optimizer hints to stabilize the plan.',
                metadata: ['volatility_score' => $volatilityScore, 'drift_contributors' => $driftContributors],
            );
        } elseif ($volatilityLabel === 'moderate') {
            $findings[] = new Finding(
                severity: Severity::Optimization,
                category: 'plan_stability',
                title: sprintf('Moderate plan volatility: %d/100', $volatilityScore),
                description: 'The execution plan has moderate volatility. Monitor for plan changes during data growth.',
                metadata: ['volatility_score' => $volatilityScore],
            );
        }

        return ['stability' => $stability, 'findings' => $findings];
    }

    /**
     * @return string[] e.g., ['USE INDEX', 'FORCE INDEX', 'STRAIGHT_JOIN']
     */
    private function detectOptimizerHints(string $rawSql): array
    {
        $hints = [];
        $patterns = [
            'USE INDEX' => '/\bUSE\s+INDEX\b/i',
            'FORCE INDEX' => '/\bFORCE\s+INDEX\b/i',
            'IGNORE INDEX' => '/\bIGNORE\s+INDEX\b/i',
            'STRAIGHT_JOIN' => '/\bSTRAIGHT_JOIN\b/i',
        ];

        foreach ($patterns as $name => $regex) {
            if (preg_match($regex, $rawSql)) {
                $hints[] = $name;
            }
        }

        return $hints;
    }

    /**
     * Assess plan flip risk by comparing estimated vs actual rows in EXPLAIN ANALYZE.
     *
     * @return array{is_risky: bool, description: string, deviations: array<int, array{estimated: int, actual: int, factor: float}>}
     */
    private function assessPlanFlipRisk(string $plan): array
    {
        $deviations = [];
        $pattern = '/\(cost=[\d.,]+\s+rows=([\d.]+)\)\s*\(actual\s+time=[\d.]+\.\.[\d.]+\s+rows=(\d+)\s+loops=(\d+)\)/';

        if (preg_match_all($pattern, $plan, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $estimated = (int) $match[1];
                $actual = (int) $match[2];

                if ($estimated < 1 || $actual < 1) {
                    continue;
                }

                $factor = $estimated > $actual
                    ? $estimated / max($actual, 1)
                    : $actual / max($estimated, 1);

                if ($factor > 5.0) {
                    $deviations[] = [
                        'estimated' => $estimated,
                        'actual' => $actual,
                        'factor' => round($factor, 1),
                    ];
                }
            }
        }

        $isRisky = ! empty($deviations);
        $description = $isRisky
            ? sprintf(
                'Optimizer estimates deviate >5x from actual rows in %d plan node(s). The optimizer may choose a different plan after ANALYZE TABLE.',
                count($deviations)
            )
            : 'Optimizer estimates are consistent with actual execution.';

        return [
            'is_risky' => $isRisky,
            'description' => $description,
            'deviations' => $deviations,
        ];
    }

    /**
     * Detect statistics drift by comparing SHOW INDEX cardinality with EXPLAIN row estimates.
     *
     * @return array<int, array{table: string, description: string, index_cardinality: int, explain_rows: int}>
     */
    private function detectStatisticsDrift(array $explainRows, ?string $connectionName = null): array
    {
        $issues = [];

        foreach ($explainRows as $row) {
            $table = $row['table'] ?? null;
            $keyUsed = $row['key'] ?? null;
            $explainRowCount = (int) ($row['rows'] ?? 0);

            if ($table === null || str_starts_with($table, '<') || $keyUsed === null) {
                continue;
            }

            $indexes = $this->getCachedIndexInfo($table, $connectionName);
            foreach ($indexes as $idx) {
                if ($idx->Key_name !== $keyUsed || ($idx->Seq_in_index ?? 1) != 1) {
                    continue;
                }

                $cardinality = (int) ($idx->Cardinality ?? 0);
                if ($cardinality < 1 || $explainRowCount < 1) {
                    continue;
                }

                $factor = $cardinality > $explainRowCount
                    ? $cardinality / max($explainRowCount, 1)
                    : $explainRowCount / max($cardinality, 1);

                if ($factor > 10.0) {
                    $issues[] = [
                        'table' => $table,
                        'description' => sprintf(
                            'Index `%s` cardinality (%s) differs >10x from EXPLAIN row estimate (%s). Statistics may be stale.',
                            $keyUsed,
                            number_format($cardinality),
                            number_format($explainRowCount)
                        ),
                        'index_cardinality' => $cardinality,
                        'explain_rows' => $explainRowCount,
                    ];
                }

                break;
            }
        }

        return $issues;
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
     * Calculate volatility score (0-100) from plan deviations, hints, and drift.
     */
    private function calculateVolatilityScore(array $flipRisk, array $hints, ?array $cardinalityDrift): int
    {
        $volatility = 0;

        // Deviations contribute to volatility
        foreach ($flipRisk['deviations'] as $deviation) {
            $volatility += (int) min($deviation['factor'] * 5, 25);
        }

        // Optimizer hints stabilize the plan
        if (!empty($hints)) {
            $volatility -= 20;
        }

        // Cardinality drift contributes
        $compositeDrift = $cardinalityDrift['composite_drift_score'] ?? 0.0;
        $volatility += (int) round($compositeDrift * 30);

        return max(0, min(100, $volatility));
    }

    /**
     * Classify volatility level.
     */
    private function classifyVolatility(int $score): string
    {
        return match (true) {
            $score >= 60 => 'volatile',
            $score >= 30 => 'moderate',
            default => 'stable',
        };
    }

    /**
     * Extract tables contributing to drift from Phase 1 data.
     *
     * @return string[]
     */
    private function extractDriftContributors(?array $cardinalityDrift): array
    {
        if ($cardinalityDrift === null) {
            return [];
        }

        $contributors = [];
        foreach ($cardinalityDrift['per_table'] ?? [] as $table => $data) {
            if (($data['drift_ratio'] ?? 0) > 0.5) {
                $contributors[] = $table;
            }
        }

        return $contributors;
    }
}
