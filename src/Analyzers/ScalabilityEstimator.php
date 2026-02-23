<?php

declare(strict_types=1);

namespace QuerySentinel\Analyzers;

use QuerySentinel\Enums\ComplexityClass;

/**
 * Projects query scalability at 1M and 10M row counts.
 *
 * Uses the complexity classification to choose between linear and
 * quadratic growth models for time and row examination projections.
 */
final class ScalabilityEstimator
{
    /**
     * @param  array<string, mixed>  $metrics  From MetricsExtractor
     * @param  int  $currentRowCount  Current driving table row count
     * @param  array<int>  $projectionTargets  Row counts to project to (default: 1M, 10M)
     * @return array<string, mixed>
     */
    public function estimate(array $metrics, int $currentRowCount, array $projectionTargets = [1_000_000, 10_000_000]): array
    {
        $currentRowCount = max($currentRowCount, 1);
        $timeMs = max($metrics['execution_time_ms'] ?? 0.01, 0.01);
        $rowsExamined = max($metrics['rows_examined'] ?? 1, 1);
        $maxLoops = max($metrics['max_loops'] ?? 1, 1);
        $complexityValue = $metrics['complexity'] ?? ComplexityClass::Linear->value;

        $complexity = ComplexityClass::tryFrom($complexityValue) ?? ComplexityClass::Linear;
        $isLinear = $this->isLinearScaling($complexity, $metrics);

        $projections = [];

        foreach ($projectionTargets as $targetRows) {
            $factor = $targetRows / $currentRowCount;

            if ($isLinear) {
                $timeFactor = $factor;
                $rowsFactor = $factor;
                $model = 'linear';
            } else {
                $timeFactor = $factor ** $complexity->scalabilityFactor();
                $rowsFactor = $timeFactor;
                $model = 'quadratic';
            }

            $projections[] = [
                'target_rows' => $targetRows,
                'growth_factor' => round($factor, 1),
                'projected_time_ms' => round($timeMs * $timeFactor, 2),
                'projected_rows_examined' => (int) round($rowsExamined * $rowsFactor),
                'model' => $model,
                'label' => round($factor, 1).'x cost ('.$model.')',
            ];
        }

        $risk = $this->assessRisk($complexity, $metrics, $projections);

        $limitSensitivity = $this->estimateLimitSensitivity(
            $metrics, $timeMs
        );

        return [
            'current_rows' => $currentRowCount,
            'complexity' => $complexity->value,
            'projections' => $projections,
            'risk' => $risk,
            'limit_sensitivity' => $limitSensitivity,
        ];
    }

    private function isLinearScaling(ComplexityClass $complexity, array $metrics): bool
    {
        $isIndexBacked = $metrics['is_index_backed'] ?? false;
        $hasTableScan = $metrics['has_table_scan'] ?? false;
        $maxLoops = $metrics['max_loops'] ?? 0;

        if ($complexity === ComplexityClass::Quadratic) {
            return false;
        }

        return $isIndexBacked && ! $hasTableScan && $maxLoops < 10_000;
    }

    private function assessRisk(ComplexityClass $complexity, array $metrics, array $projections): string
    {
        if ($complexity === ComplexityClass::Quadratic) {
            return 'HIGH';
        }

        if ($complexity === ComplexityClass::Limit || $complexity === ComplexityClass::Range) {
            $maxProjectedTime = 0;
            foreach ($projections as $p) {
                $maxProjectedTime = max($maxProjectedTime, $p['projected_time_ms']);
            }

            return $maxProjectedTime > 10_000 ? 'MEDIUM' : 'LOW';
        }

        $maxCost = $metrics['max_cost'] ?? 0;
        $hasTableScan = $metrics['has_table_scan'] ?? false;

        if ($hasTableScan || $maxCost > 1e6) {
            return 'HIGH';
        }

        return 'MEDIUM';
    }

    /**
     * Estimate how execution time changes at different LIMIT values.
     *
     * @return array<int, array{projected_time_ms: float, note: string}>
     */
    private function estimateLimitSensitivity(array $metrics, float $currentTimeMs): array
    {
        $hasEarlyTermination = $metrics['has_early_termination'] ?? false;
        $rowsReturned = max($metrics['rows_returned'] ?? 1, 1);

        $limits = [100, 500, 1000];
        $sensitivity = [];

        foreach ($limits as $limit) {
            if ($hasEarlyTermination) {
                $factor = $limit / $rowsReturned;
                $projectedTime = round($currentTimeMs * max($factor, 1.0), 2);
                $note = $factor > 10 ? 'May trigger plan change at this LIMIT' : 'Linear scaling expected';
            } else {
                $projectedTime = round($currentTimeMs, 2);
                $note = 'LIMIT does not reduce work (no early termination)';
            }

            $sensitivity[$limit] = [
                'projected_time_ms' => $projectedTime,
                'note' => $note,
            ];
        }

        return $sensitivity;
    }
}
