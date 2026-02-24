<?php

declare(strict_types=1);

namespace QuerySentinel\Analyzers;

use QuerySentinel\Enums\Severity;
use QuerySentinel\Support\Finding;

/**
 * Phase 1: Cardinality Drift & Estimation Accuracy.
 *
 * Detects when optimizer row estimates diverge from actual rows,
 * quantifies drift severity, and flags tables needing ANALYZE TABLE.
 */
final class CardinalityDriftAnalyzer
{
    private float $warningThreshold;

    private float $criticalThreshold;

    public function __construct(float $warningThreshold = 0.5, float $criticalThreshold = 0.9)
    {
        $this->warningThreshold = $warningThreshold;
        $this->criticalThreshold = $criticalThreshold;
    }

    /**
     * @param  array<string, mixed>  $metrics  From MetricsExtractor (needs per_table_estimates)
     * @param  array<int, array<string, mixed>>  $explainRows  From EXPLAIN tabular output
     * @return array{cardinality_drift: array<string, mixed>, findings: Finding[]}
     */
    public function analyze(array $metrics, array $explainRows): array
    {
        $findings = [];
        $perTable = [];
        $tablesNeedingAnalyze = [];

        $perTableEstimates = $metrics['per_table_estimates'] ?? [];

        foreach ($perTableEstimates as $table => $data) {
            $estimated = (float) ($data['estimated_rows'] ?? 0);
            $actual = (int) ($data['actual_rows'] ?? 0);
            $loops = (int) ($data['loops'] ?? 1);

            // Scale by loops for total work
            $totalActual = $actual * $loops;
            $totalEstimated = $estimated * $loops;

            $denominator = max($totalEstimated, $totalActual, 1);
            $driftRatio = round(abs($totalEstimated - $totalActual) / $denominator, 4);

            if ($totalEstimated > $totalActual * 1.1) {
                $direction = 'over';
            } elseif ($totalActual > $totalEstimated * 1.1) {
                $direction = 'under';
            } else {
                $direction = 'accurate';
            }

            $severity = 'info';
            if ($driftRatio > $this->criticalThreshold) {
                $severity = 'critical';
            } elseif ($driftRatio > $this->warningThreshold) {
                $severity = 'warning';
            } elseif ($driftRatio > 0.2) {
                $severity = 'optimization';
            }

            $perTable[$table] = [
                'estimated_rows' => (int) $totalEstimated,
                'actual_rows' => $totalActual,
                'drift_ratio' => $driftRatio,
                'drift_direction' => $direction,
                'severity' => $severity,
            ];

            if ($driftRatio > $this->warningThreshold) {
                $tablesNeedingAnalyze[] = $table;

                $findings[] = new Finding(
                    severity: $severity === 'critical' ? Severity::Critical : Severity::Warning,
                    category: 'cardinality_drift',
                    title: sprintf('Cardinality drift on `%s`: %.0f%% %sestimation', $table, $driftRatio * 100, $direction === 'over' ? 'over-' : 'under-'),
                    description: sprintf(
                        'Optimizer estimated %s rows but actual was %s (drift ratio: %.2f). %s.',
                        number_format((int) $totalEstimated),
                        number_format($totalActual),
                        $driftRatio,
                        $direction === 'over'
                            ? 'The optimizer overestimates, which may cause suboptimal join ordering'
                            : 'The optimizer underestimates, which may cause insufficient buffer allocation'
                    ),
                    recommendation: sprintf('ANALYZE TABLE `%s`;', $table),
                    metadata: [
                        'table' => $table,
                        'estimated' => (int) $totalEstimated,
                        'actual' => $totalActual,
                        'drift_ratio' => $driftRatio,
                        'direction' => $direction,
                    ],
                );
            }
        }

        // Also check EXPLAIN rows vs per_table_estimates for additional drift signals
        foreach ($explainRows as $row) {
            $table = $row['table'] ?? null;
            $explainEstimate = (int) ($row['rows'] ?? 0);

            if ($table === null || str_starts_with($table, '<') || $explainEstimate < 1) {
                continue;
            }

            if (isset($perTableEstimates[$table]) && ! isset($perTable[$table])) {
                // Already processed above
                continue;
            }

            if (! isset($perTable[$table]) && isset($perTableEstimates[$table])) {
                continue; // Already handled
            }
        }

        $compositeDriftScore = $this->calculateCompositeDriftScore($perTable);

        return [
            'cardinality_drift' => [
                'per_table' => $perTable,
                'composite_drift_score' => $compositeDriftScore,
                'tables_needing_analyze' => $tablesNeedingAnalyze,
            ],
            'findings' => $findings,
        ];
    }

    /**
     * Weighted average drift score across all tables, weighted by actual rows.
     *
     * @param  array<string, array<string, mixed>>  $perTable
     */
    private function calculateCompositeDriftScore(array $perTable): float
    {
        if (empty($perTable)) {
            return 0.0;
        }

        $totalWeight = 0;
        $weightedSum = 0.0;

        foreach ($perTable as $data) {
            $weight = max((int) ($data['actual_rows'] ?? 1), 1);
            $weightedSum += $data['drift_ratio'] * $weight;
            $totalWeight += $weight;
        }

        return round($weightedSum / $totalWeight, 4);
    }
}
