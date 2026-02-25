<?php

declare(strict_types=1);

namespace QuerySentinel\Scoring;

use QuerySentinel\Enums\Severity;
use QuerySentinel\Support\EnvironmentContext;
use QuerySentinel\Support\Finding;

/**
 * Phase 5: Confidence Score System.
 *
 * Attaches a confidence score (0.0–1.0) to the overall analysis,
 * reflecting how trustworthy the results are based on 8 weighted factors.
 */
final class ConfidenceScorer
{
    /**
     * @param  array<string, mixed>  $metrics  From MetricsExtractor
     * @param  ?array<string, mixed>  $cardinalityDrift  From CardinalityDriftAnalyzer (Phase 1)
     * @param  ?array<string, mixed>  $stabilityAnalysis  From PlanStabilityAnalyzer
     * @param  bool  $supportsAnalyze  Whether EXPLAIN ANALYZE is available
     * @return array{confidence: array<string, mixed>, findings: Finding[]}
     */
    public function score(
        array $metrics,
        ?array $cardinalityDrift,
        ?array $stabilityAnalysis,
        ?EnvironmentContext $environment,
        bool $supportsAnalyze,
    ): array {
        $findings = [];
        $factors = [];

        // Factor 1: Estimation accuracy (weight 0.25)
        $compositeDrift = $cardinalityDrift['composite_drift_score'] ?? 0.0;
        $estimationScore = max(0.0, 1.0 - $compositeDrift);
        $factors[] = [
            'name' => 'estimation_accuracy',
            'score' => round($estimationScore, 2),
            'weight' => 0.25,
            'note' => $compositeDrift > 0.5 ? 'High drift between estimated and actual rows' : 'Optimizer estimates are reasonably accurate',
        ];

        // Factor 2: Sample size (weight 0.20)
        // For CONST/EQ_REF access, sample size is irrelevant — the result is deterministic.
        $actualRows = 0;
        foreach (($metrics['per_table_estimates'] ?? []) as $table) {
            $actualRows += ($table['actual_rows'] ?? 0) * ($table['loops'] ?? 1);
        }

        $accessType = $metrics['primary_access_type'] ?? null;
        $isConstAccess = in_array($accessType, ['zero_row_const', 'const_row', 'single_row_lookup'], true);

        if ($isConstAccess) {
            $sampleScore = 1.0;
            $sampleNote = 'Const/unique lookup — deterministic result, sample size is irrelevant';
        } else {
            $sampleScore = min($actualRows / 1000.0, 1.0);
            $sampleNote = $actualRows < 100
                ? 'Very small sample — results may not reflect production'
                : sprintf('%s rows processed', number_format($actualRows));
        }

        $factors[] = [
            'name' => 'sample_size',
            'score' => round($sampleScore, 2),
            'weight' => 0.20,
            'note' => $sampleNote,
        ];

        // Factor 3: EXPLAIN ANALYZE availability (weight 0.15)
        $analyzeScore = $supportsAnalyze ? 1.0 : 0.3;
        $factors[] = [
            'name' => 'explain_analyze',
            'score' => $analyzeScore,
            'weight' => 0.15,
            'note' => $supportsAnalyze ? 'EXPLAIN ANALYZE available — actual execution metrics collected' : 'Only EXPLAIN available — estimates only, no actual timings',
        ];

        // Factor 4: Cache warmth (weight 0.10)
        $isCold = $environment?->isColdCache ?? true;
        $cacheScore = $isCold ? 0.5 : 1.0;
        $factors[] = [
            'name' => 'cache_warmth',
            'score' => $cacheScore,
            'weight' => 0.10,
            'note' => $isCold ? 'Buffer pool is cold — timing measurements may be inflated' : 'Buffer pool is warm — timings reflect steady-state',
        ];

        // Factor 5: Statistics freshness (weight 0.10)
        $tablesNeeding = $cardinalityDrift['tables_needing_analyze'] ?? [];
        $totalTables = count($metrics['tables_accessed'] ?? []);
        $staleRatio = $totalTables > 0 ? count($tablesNeeding) / $totalTables : 0.0;
        $statsScore = max(0.0, 1.0 - $staleRatio);
        $factors[] = [
            'name' => 'statistics_freshness',
            'score' => round($statsScore, 2),
            'weight' => 0.10,
            'note' => empty($tablesNeeding) ? 'All table statistics appear fresh' : sprintf('%d of %d tables have stale statistics', count($tablesNeeding), $totalTables),
        ];

        // Factor 6: Plan stability (weight 0.10)
        $isRisky = $stabilityAnalysis['plan_flip_risk']['is_risky'] ?? false;
        $planScore = $isRisky ? 0.5 : 1.0;
        $factors[] = [
            'name' => 'plan_stability',
            'score' => $planScore,
            'weight' => 0.10,
            'note' => $isRisky ? 'Plan flip risk detected — optimizer may choose different plans' : 'Execution plan is stable',
        ];

        // Factor 7: Query complexity (weight 0.05)
        $joinCount = $metrics['join_count'] ?? 0;
        $complexityScore = $joinCount > 3 ? 0.7 : 1.0;
        $factors[] = [
            'name' => 'query_complexity',
            'score' => $complexityScore,
            'weight' => 0.05,
            'note' => $joinCount > 3 ? sprintf('Complex query with %d tables — analysis may miss edge cases', $joinCount) : 'Simple query structure',
        ];

        // Factor 8: Driver capabilities (weight 0.05)
        $driverScore = $supportsAnalyze ? 1.0 : 0.6;
        $factors[] = [
            'name' => 'driver_capabilities',
            'score' => $driverScore,
            'weight' => 0.05,
            'note' => $supportsAnalyze ? 'Full driver capabilities available' : 'Limited driver capabilities — some analysis features unavailable',
        ];

        // Calculate overall confidence
        $overall = 0.0;
        foreach ($factors as $factor) {
            $overall += $factor['score'] * $factor['weight'];
        }
        $overall = round($overall, 4);

        $label = match (true) {
            $overall >= 0.9 => 'high',
            $overall >= 0.7 => 'moderate',
            $overall >= 0.5 => 'low',
            default => 'unreliable',
        };

        // Generate finding if confidence is low
        if ($overall < 0.5) {
            $findings[] = new Finding(
                severity: Severity::Warning,
                category: 'confidence',
                title: sprintf('Low analysis confidence: %.0f%%', $overall * 100),
                description: 'The analysis confidence is below 50%. Results should be interpreted with caution due to limited data quality or stale statistics.',
                recommendation: 'Run ANALYZE TABLE on affected tables, ensure EXPLAIN ANALYZE is available, and warm the buffer pool before re-analyzing.',
            );
        } elseif ($overall < 0.7) {
            $findings[] = new Finding(
                severity: Severity::Optimization,
                category: 'confidence',
                title: sprintf('Moderate analysis confidence: %.0f%%', $overall * 100),
                description: 'Analysis confidence is moderate. Some factors (stale statistics, cold cache, or limited sample) reduce certainty.',
            );
        }

        return [
            'confidence' => [
                'overall' => $overall,
                'label' => $label,
                'factors' => $factors,
            ],
            'findings' => $findings,
        ];
    }
}
