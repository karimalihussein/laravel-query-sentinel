<?php

declare(strict_types=1);

namespace QuerySentinel\Scoring;

use QuerySentinel\Contracts\ScoringEngineInterface;
use QuerySentinel\Enums\ComplexityClass;

/**
 * Weighted composite scoring engine for query performance.
 *
 * Scores five independent components (execution time, scan efficiency,
 * index quality, join efficiency, scalability), applies configurable weights,
 * and produces a 0-100 composite score with letter grade.
 *
 * Context override: LIMIT-optimized + covering index + no filesort + fast
 * queries are auto-promoted to grade A (score clamped to 95+).
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
            'A' => 90,
            'B' => 75,
            'C' => 50,
            'D' => 25,
            'F' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @return array{composite_score: float, grade: string, breakdown: array<string, array{score: int, weight: float, weighted: float}>, context_override: bool}
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

        // Context override: LIMIT-optimized + covering + no filesort + fast = auto A
        $contextOverride = $this->shouldApplyContextOverride($metrics, $compositeScore);
        if ($contextOverride) {
            $compositeScore = max($compositeScore, 95.0);
        }

        $grade = $this->calculateGrade($compositeScore);

        return [
            'composite_score' => $compositeScore,
            'grade' => $grade,
            'breakdown' => $breakdown,
            'context_override' => $contextOverride,
        ];
    }

    /**
     * Score execution time: 0-100.
     *
     * < 1ms → 100, 1-10ms → 90-100, 10-100ms → 70-90,
     * 100-1000ms → 30-70, > 1000ms → 0-30
     */
    private function scoreExecutionTime(array $metrics): int
    {
        $timeMs = $metrics['execution_time_ms'] ?? 0.0;

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
     * Score scan efficiency: 0-100 based on selectivity ratio.
     *
     * Selectivity = rows_examined / max(rows_returned, 1).
     * Ratio 1.0 → 100, 2-10 → 80-100, 10-100 → 50-80,
     * 100-1000 → 20-50, > 1000 → 0-20.
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
     * Score index quality: 0-100 based on index usage characteristics.
     *
     * Start at 100, deduct for anti-patterns:
     * - Table scan: -40
     * - No index backing: -30
     * - Index merge (suboptimal): -20
     * - No covering index (minor): -10
     */
    private function scoreIndexQuality(array $metrics): int
    {
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
     *
     * depth 0-2 → 100, depth 3 → 80, depth 4+ → 60 - (depth * 5)
     * Additional deductions for high fanout.
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

        // Fanout penalty
        if ($fanout > 10_000) {
            $score -= 30;
        } elseif ($fanout > 1_000) {
            $score -= 20;
        } elseif ($fanout > 100) {
            $score -= 10;
        }

        // Temp table and weedout penalties
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
     * O(limit) → 100, O(range) → 80, O(n) → 50,
     * O(n log n) → 30, O(n²) → 10.
     */
    private function scoreScalability(array $metrics): int
    {
        $complexityValue = $metrics['complexity'] ?? ComplexityClass::Linear->value;
        $complexity = ComplexityClass::tryFrom($complexityValue) ?? ComplexityClass::Linear;

        $baseScore = match ($complexity) {
            ComplexityClass::Limit => 100,
            ComplexityClass::Range => 80,
            ComplexityClass::Linear => 50,
            ComplexityClass::Linearithmic => 30,
            ComplexityClass::Quadratic => 10,
        };

        // Bonus for early termination with any complexity
        if ($metrics['has_early_termination'] ?? false) {
            $baseScore = min(100, $baseScore + 20);
        }

        return $baseScore;
    }

    /**
     * Check if context override should apply.
     *
     * LIMIT-optimized + covering index + no filesort + execution < 10ms
     * = query is structurally excellent, auto-promote to A.
     */
    private function shouldApplyContextOverride(array $metrics, float $currentScore): bool
    {
        if ($currentScore >= 90.0) {
            return false;
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
