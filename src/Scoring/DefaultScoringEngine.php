<?php

declare(strict_types=1);

namespace QuerySentinel\Scoring;

use QuerySentinel\Contracts\ScoringEngineInterface;
use QuerySentinel\Enums\ComplexityClass;

/**
 * Weighted composite scoring engine for query performance.
 *
 * Scores five independent components from actual plan metrics:
 *   execution_time_score   - wall clock time
 *   scan_efficiency_score  - rows examined vs returned
 *   index_quality_score    - access type quality
 *   join_efficiency_score  - nested loop depth and fanout
 *   scalability_score      - complexity classification
 *
 * Score must never contradict risk. Components are derived from
 * measurable factors only — no arbitrary bonuses or penalties.
 */
final class DefaultScoringEngine implements ScoringEngineInterface
{
    /** @var array<string, float> */
    private readonly array $weights;

    /** @var array<string, int> */
    private readonly array $gradeThresholds;

    /**
     * @param  array<string, float>  $weights
     * @param  array<string, int>  $gradeThresholds
     */
    public function __construct(array $weights = [], array $gradeThresholds = [])
    {
        $this->weights = $weights ?: [
            'execution_time' => 0.30,
            'scan_efficiency' => 0.25,
            'index_quality' => 0.20,
            'join_efficiency' => 0.15,
            'scalability' => 0.10,
        ];

        $this->gradeThresholds = $gradeThresholds ?: [
            'A+' => 98,
            'A' => 90,
            'B' => 75,
            'C' => 50,
            'D' => 25,
            'F' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @return array{composite_score: float, grade: string, breakdown: array<string, array{score: int, weight: float, weighted: float}>, context_override: bool, dataset_dampened: bool}
     */
    public function score(array $metrics): array
    {
        $components = [
            'execution_time' => $this->scoreExecutionTime($metrics),
            'scan_efficiency' => $this->scoreScanEfficiency($metrics),
            'index_quality' => $this->scoreIndexQuality($metrics),
            'join_efficiency' => $this->scoreJoinEfficiency($metrics),
            'scalability' => $this->scoreScalability($metrics),
        ];

        $breakdown = [];
        $compositeScore = 0.0;

        foreach ($components as $component => $score) {
            $weight = $this->weights[$component] ?? 0.0;
            $weighted = $score * $weight;
            $compositeScore += $weighted;

            $breakdown[$component] = [
                'score' => $score,
                'weight' => $weight,
                'weighted' => round($weighted, 2),
            ];
        }

        $compositeScore = round($compositeScore, 1);

        // Context override: zero-row const or LIMIT-optimized + covering + no filesort + fast
        $contextOverride = $this->shouldApplyContextOverride($metrics, $compositeScore);
        if ($contextOverride) {
            // Intentional scans cap at 95 (never truly A+ — inherent scalability cost)
            $overrideCap = ($metrics['is_intentional_scan'] ?? false) ? 95.0 : 98.0;
            $compositeScore = max($compositeScore, $overrideCap);
        }

        // Dampen score for large unbounded result sets
        $preDampenScore = $compositeScore;
        $compositeScore = $this->dampenForDatasetSize($compositeScore, $metrics);
        $datasetDampened = $compositeScore < $preDampenScore;

        $grade = $this->calculateGrade($compositeScore);

        return [
            'composite_score' => $compositeScore,
            'grade' => $grade,
            'breakdown' => $breakdown,
            'context_override' => $contextOverride,
            'dataset_dampened' => $datasetDampened,
        ];
    }

    /**
     * Apply confidence gate to a previously computed score.
     *
     * Centralizes the confidence-gating logic used by DiagnosticReport
     * and the engine pipeline.
     *
     * @return array{composite_score: float, confidence_capped: bool}
     */
    public function applyConfidenceGate(float $compositeScore, float $confidence): array
    {
        $capped = false;

        if ($confidence < 0.5) {
            $compositeScore = min($compositeScore, 75.0);
            $capped = true;
        } elseif ($confidence < 0.7) {
            $compositeScore = min($compositeScore, 90.0);
            $capped = true;
        }

        return [
            'composite_score' => $compositeScore,
            'confidence_capped' => $capped,
        ];
    }

    /**
     * Score execution time: 0-100.
     *
     * Three regimes:
     *   >10K rows: pure deviation-based (time_per_row vs expected baseline)
     *   1K-10K rows: blended transition (absolute + deviation weighted by row count)
     *   <1K rows: pure absolute time (total time is the correct metric)
     */
    private function scoreExecutionTime(array $metrics): int
    {
        $timeMs = $metrics['execution_time_ms'] ?? 0.0;
        $rowsExamined = $metrics['rows_examined'] ?? 0;

        // Large datasets (>10K rows): pure deviation-based scoring
        if ($rowsExamined > 10_000 && $timeMs > 0) {
            return $this->scoreExecutionTimeByDeviation($metrics, $timeMs, $rowsExamined);
        }

        // Medium datasets (1K-10K rows): blended transition zone
        if ($rowsExamined >= 1_000 && $timeMs > 0) {
            $absoluteScore = $this->scoreExecutionTimeAbsolute($timeMs);
            $deviationScore = $this->scoreExecutionTimeByDeviation($metrics, $timeMs, $rowsExamined);
            $weight = ($rowsExamined - 1_000) / 9_000;

            return (int) round($absoluteScore * (1.0 - $weight) + $deviationScore * $weight);
        }

        // Small datasets (<1K rows): absolute time is the correct metric
        return $this->scoreExecutionTimeAbsolute($timeMs);
    }

    /**
     * Score execution time using absolute thresholds.
     *
     * Appropriate for small result sets where total wall-clock time is the right metric.
     * A query returning 5 rows in 500ms is genuinely slow regardless of per-row cost.
     */
    private function scoreExecutionTimeAbsolute(float $timeMs): int
    {
        if ($timeMs < 1.0) {
            return 100;
        }
        if ($timeMs < 10.0) {
            return (int) round(100 - ($timeMs - 1) * (10.0 / 9.0));
        }
        if ($timeMs < 100.0) {
            return (int) round(90 - ($timeMs - 10) * (20.0 / 90.0));
        }
        if ($timeMs < 1000.0) {
            return (int) round(70 - ($timeMs - 100) * (40.0 / 900.0));
        }
        if ($timeMs < 10000.0) {
            return (int) round(30 - ($timeMs - 1000) * (30.0 / 9000.0));
        }

        return 0;
    }

    /**
     * Score execution time using deviation from expected per-row baseline.
     *
     * Appropriate for large datasets where per-row efficiency matters.
     * Compares actual time_per_row against expected baseline for the access type.
     */
    private function scoreExecutionTimeByDeviation(array $metrics, float $timeMs, int $rowsExamined): int
    {
        $timePerRowUs = ($timeMs / $rowsExamined) * 1000.0; // μs per row
        $expectedUs = $this->expectedTimePerRowUs($metrics);
        $ratio = $timePerRowUs / $expectedUs;

        if ($ratio <= 1.0) {
            return 100;
        }
        if ($ratio <= 2.0) {
            return 95;
        }
        if ($ratio <= 5.0) {
            return 85;
        }
        if ($ratio <= 10.0) {
            return 70;
        }
        if ($ratio <= 25.0) {
            return 50;
        }
        if ($ratio <= 50.0) {
            return 30;
        }

        return 10;
    }

    /**
     * Expected cost per row (μs) by access type.
     *
     * InnoDB sequential scan: ~0.3 μs/row (16KB pages, ~64 rows/page, SSD).
     * Index range scan: ~0.1 μs/row (fewer pages, better locality).
     * Index lookup: ~0.05 μs/row (B-tree traversal + single page).
     * Full scan with joins: ~1.0 μs/row (buffer pool churn, nested loops).
     *
     * @param  array<string, mixed>  $metrics
     */
    private function expectedTimePerRowUs(array $metrics): float
    {
        $accessType = $metrics['primary_access_type'] ?? 'table_scan';
        $hasJoins = ($metrics['nested_loop_depth'] ?? 0) > 0;

        if ($hasJoins) {
            return 1.0;
        }

        return match ($accessType) {
            'zero_row_const', 'const_row', 'single_row_lookup' => 0.05,
            'covering_index_lookup', 'index_lookup', 'fulltext_index' => 0.1,
            'index_range_scan' => 0.15,
            'index_scan' => 0.25,
            default => 0.3, // table_scan — sequential I/O baseline
        };
    }

    /**
     * Score scan efficiency: 0-100 based on selectivity ratio.
     */
    private function scoreScanEfficiency(array $metrics): int
    {
        $examined = $metrics['rows_examined'] ?? 0;
        $returned = max($metrics['rows_returned'] ?? 1, 1);
        $ratio = $examined / $returned;

        if ($examined === 0 && $returned === 0) {
            return 100;
        }

        if ($ratio <= 1.0) {
            return 100;
        }
        if ($ratio <= 2.0) {
            return 95;
        }
        if ($ratio <= 10.0) {
            return (int) round(100 - ($ratio - 1) * (20.0 / 9.0));
        }
        if ($ratio <= 100.0) {
            return (int) round(80 - ($ratio - 10) * (30.0 / 90.0));
        }
        if ($ratio <= 1000.0) {
            return (int) round(50 - ($ratio - 100) * (30.0 / 900.0));
        }

        return max(0, (int) round(20 - ($ratio - 1000) * (20.0 / 99000.0)));
    }

    /**
     * Score index quality: 0-100 based on access type and index usage.
     *
     * Const/eq_ref access: 100 (perfect — no deductions).
     * Otherwise: start at 100, deduct for anti-patterns.
     */
    private function scoreIndexQuality(array $metrics): int
    {
        $accessType = $metrics['primary_access_type'] ?? null;
        $isZeroRowConst = $metrics['is_zero_row_const'] ?? false;

        // Const-level access: perfect index quality
        if ($isZeroRowConst || in_array($accessType, ['zero_row_const', 'const_row', 'single_row_lookup'], true)) {
            return 100;
        }

        // Intentional full scan: no filtering predicates exist, index cannot help
        if ($metrics['is_intentional_scan'] ?? false) {
            return 100;
        }

        $score = 100;

        if ($metrics['has_table_scan'] ?? false) {
            $score -= 40;
        }

        if (! ($metrics['is_index_backed'] ?? false)) {
            $score -= 30;
        }

        if ($metrics['has_index_merge'] ?? false) {
            $score -= 20;
        }

        if (! ($metrics['has_covering_index'] ?? false) && ! ($metrics['has_table_scan'] ?? false)) {
            $score -= 10;
        }

        return max(0, $score);
    }

    /**
     * Score join efficiency: 0-100 based on nested loop depth and fanout.
     */
    private function scoreJoinEfficiency(array $metrics): int
    {
        $depth = $metrics['nested_loop_depth'] ?? 0;
        $fanout = $metrics['fanout_factor'] ?? 1.0;

        if ($depth <= 2) {
            $score = 100;
        } elseif ($depth === 3) {
            $score = 80;
        } else {
            $score = max(20, 60 - ($depth * 5));
        }

        // Fanout penalty only applies when joins exist (depth > 0).
        // For single-table scans, "fanout" is just the table's row count, not join explosion.
        if ($depth > 0) {
            if ($fanout > 10_000) {
                $score -= 30;
            } elseif ($fanout > 1_000) {
                $score -= 20;
            } elseif ($fanout > 100) {
                $score -= 10;
            }
        }

        if ($metrics['has_weedout'] ?? false) {
            $score -= 15;
        }
        if ($metrics['has_temp_table'] ?? false) {
            $score -= 10;
        }

        return max(0, $score);
    }

    /**
     * Score scalability: 0-100 based on complexity classification.
     *
     * O(1) = 100, O(log n) = 90, O(log n + k) = 80,
     * O(n) = 50, O(n log n) = 30, O(n²) = 10.
     */
    private function scoreScalability(array $metrics): int
    {
        $complexityValue = $metrics['complexity'] ?? ComplexityClass::Linear->value;
        $complexity = ComplexityClass::tryFrom($complexityValue) ?? ComplexityClass::Linear;

        $baseScore = match ($complexity) {
            ComplexityClass::Constant => 100,
            ComplexityClass::Logarithmic => 90,
            ComplexityClass::LogRange => 80,
            ComplexityClass::Linear => 50,
            ComplexityClass::Linearithmic => 30,
            ComplexityClass::Quadratic => 10,
        };

        // Intentional full scan: linear is by design, not a penalty
        if (($metrics['is_intentional_scan'] ?? false) && $complexity === ComplexityClass::Linear) {
            return 100;
        }

        // Bonus for early termination
        if ($metrics['has_early_termination'] ?? false) {
            $baseScore = min(100, $baseScore + 20);
        }

        return $baseScore;
    }

    /**
     * Check if context override should apply.
     *
     * Zero-row const queries or LIMIT-optimized + covering index + no filesort + fast
     * queries are auto-promoted.
     */
    private function shouldApplyContextOverride(array $metrics, float $currentScore): bool
    {
        if ($currentScore >= 98.0) {
            return false;
        }

        // Zero-row const: always A+
        if ($metrics['is_zero_row_const'] ?? false) {
            return true;
        }

        // Intentional full scan with fast execution: promote (capped at 95 in score())
        if (($metrics['is_intentional_scan'] ?? false) && ($metrics['execution_time_ms'] ?? 0.0) < 100.0) {
            return true;
        }

        // Const access with fast execution: always A+
        $accessType = $metrics['primary_access_type'] ?? null;
        if (in_array($accessType, ['zero_row_const', 'const_row', 'single_row_lookup'], true)) {
            $executionTimeMs = $metrics['execution_time_ms'] ?? 0.0;
            if ($executionTimeMs < 10.0) {
                return true;
            }
        }

        $hasEarlyTermination = $metrics['has_early_termination'] ?? false;
        $hasCoveringIndex = $metrics['has_covering_index'] ?? false;
        $hasFilesort = $metrics['has_filesort'] ?? false;
        $executionTimeMs = $metrics['execution_time_ms'] ?? 0.0;

        return $hasEarlyTermination
            && $hasCoveringIndex
            && ! $hasFilesort
            && $executionTimeMs < 10.0;
    }

    /**
     * Dampen composite score for large unbounded result sets.
     *
     * No query returning >10K rows should receive a perfect score.
     * The inherent scalability cost of large dataset retrieval must be reflected.
     *
     * Formula: max_score = 98 - log10(rows_returned / 10000) * 2, clamped to [85, 98]
     *   10K rows -> 98, 100K -> 96, 1M -> 94, 10M -> 92
     *
     * @param  array<string, mixed>  $metrics
     */
    private function dampenForDatasetSize(float $compositeScore, array $metrics): float
    {
        if (! ($metrics['is_intentional_scan'] ?? false) || ($metrics['rows_returned'] ?? 0) <= 10_000) {
            return $compositeScore;
        }

        $rowsReturned = $metrics['rows_returned'];
        $maxAllowed = 98.0 - log10($rowsReturned / 10_000) * 2.0;
        $maxAllowed = max(85.0, min(98.0, $maxAllowed));

        return min($compositeScore, $maxAllowed);
    }

    private function calculateGrade(float $score): string
    {
        foreach ($this->gradeThresholds as $grade => $threshold) {
            if ($score >= $threshold) {
                return $grade;
            }
        }

        return 'F';
    }
}
