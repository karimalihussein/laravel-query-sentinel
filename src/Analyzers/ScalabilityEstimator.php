<?php

declare(strict_types=1);

namespace QuerySentinel\Analyzers;

use QuerySentinel\Enums\ComplexityClass;

/**
 * Cost-based scalability projection engine.
 *
 * Separates fixed overhead from variable cost, uses page-based scaling
 * for linear/scan access types, and provides confidence-ranged projections.
 *
 * Growth formulas:
 *   O(1)         → constant (no growth, 100% fixed cost)
 *   O(log n)     → logarithmic: log(N) / log(n), 100% fixed cost
 *   O(log n + k) → sub-linear: sqrt(N/n)
 *   O(n)         → linear: page-based (pages = ceil(rows / rows_per_page))
 *   O(n log n)   → linearithmic: page-based × log factor
 *   O(n²)        → quadratic: page-based²
 *
 * InnoDB reads data in 16KB pages (~100 rows/page). Full scans scale
 * by pages read, not individual rows.
 */
final class ScalabilityEstimator
{
    /**
     * @param  array<string, mixed>  $metrics  From MetricsExtractor
     * @param  int  $currentRowCount  Current driving table row count
     * @param  array<int>  $projectionTargets  Row counts to project to (default: 1M, 10M)
     * @param  string|null  $sql  Raw SQL for structural analysis
     * @return array<string, mixed>
     */
    public function estimate(array $metrics, int $currentRowCount, array $projectionTargets = [1_000_000, 10_000_000], ?string $sql = null): array
    {
        $currentRowCount = max($currentRowCount, 1);
        $timeMs = max($metrics['execution_time_ms'] ?? 0.01, 0.01);
        $complexityValue = $metrics['complexity'] ?? ComplexityClass::Linear->value;
        $complexity = ComplexityClass::tryFrom($complexityValue) ?? ComplexityClass::Linear;
        $isZeroRowConst = $metrics['is_zero_row_const'] ?? false;

        // Zero-row const: force stable regardless of other metrics
        if ($isZeroRowConst) {
            $complexity = ComplexityClass::Constant;
        }

        // Separate fixed overhead from variable per-row cost
        $costs = $this->separateCosts($timeMs, $currentRowCount, $complexity);
        $fixedMs = $costs['fixed_ms'];
        $variableMs = $costs['variable_ms'];

        $projections = [];
        $maxProjectedTime = 0.0;

        foreach ($projectionTargets as $targetRows) {
            $extrapolationRatio = $targetRows / max($currentRowCount, 1);

            // Page-based scaling for scan types; mathematical growth for index types
            if (in_array($complexity, [ComplexityClass::Linear, ComplexityClass::Linearithmic, ComplexityClass::Quadratic], true)) {
                $pageFactor = $this->computePageBasedFactor($currentRowCount, $targetRows);
                $growthFactor = match ($complexity) {
                    ComplexityClass::Linearithmic => $pageFactor * max(1.0, log($targetRows, 2) / log(max($currentRowCount, 2), 2)),
                    ComplexityClass::Quadratic => $pageFactor * $pageFactor,
                    default => $pageFactor,
                };
            } else {
                $growthFactor = $this->computeGrowthFactor($complexity, $currentRowCount, $targetRows);
            }

            $projectedTotal = round($fixedMs + $variableMs * $growthFactor, 2);
            $maxProjectedTime = max($maxProjectedTime, $projectedTotal);
            $model = $this->modelLabel($complexity);

            // Display factor: effective overall multiplier
            $displayFactor = $timeMs > 0 ? round($projectedTotal / $timeMs, 1) : round($growthFactor, 1);

            // Confidence based on extrapolation ratio
            $confidence = match (true) {
                $extrapolationRatio > 10_000 => 'low',
                $extrapolationRatio > 100 => 'moderate',
                default => 'high',
            };

            // Range bounds: wider for large extrapolation ratios
            $rangeLow = $extrapolationRatio > 100 ? 0.3 : 0.7;
            $rangeHigh = $extrapolationRatio > 100 ? 3.0 : 1.5;

            $projections[] = [
                'target_rows' => $targetRows,
                'growth_factor' => $displayFactor,
                'projected_time_ms' => $projectedTotal,
                'projected_lower_ms' => round($projectedTotal * $rangeLow, 2),
                'projected_upper_ms' => round($projectedTotal * $rangeHigh, 2),
                'projected_rows_examined' => (int) round(max($metrics['rows_examined'] ?? 0, 1) * ($targetRows / max($currentRowCount, 1))),
                'model' => $model,
                'confidence' => $confidence,
                'label' => $displayFactor.'x cost ('.$model.', page-based)',
            ];
        }

        $metricsWithRows = array_merge($metrics, ['current_rows' => $currentRowCount]);
        $risk = $this->assessRisk($complexity, $metricsWithRows, $maxProjectedTime);

        $limitSensitivity = $this->estimateLimitSensitivity($metrics, $timeMs, $sql);

        // Linear sub-classification
        $linearSubtype = null;
        if ($complexity === ComplexityClass::Linear || $complexity === ComplexityClass::Linearithmic) {
            $linearSubtype = $this->classifyLinearSubtype($metrics, $sql);
        }

        return [
            'current_rows' => $currentRowCount,
            'complexity' => $complexity->value,
            'projections' => $projections,
            'risk' => $risk,
            'limit_sensitivity' => $limitSensitivity,
            'cost_model' => [
                'fixed_ms' => $costs['fixed_ms'],
                'variable_ms' => $costs['variable_ms'],
            ],
            'is_intentional_scan' => $metrics['is_intentional_scan'] ?? false,
            'linear_subtype' => $linearSubtype,
        ];
    }

    /**
     * Separate total execution time into fixed overhead and variable per-row cost.
     *
     * Small tables are dominated by fixed overhead (query parse, plan compile,
     * connection setup, buffer pool warmup). The variable cost per row is a
     * negligible fraction of total time.
     *
     * @return array{fixed_ms: float, variable_ms: float}
     */
    private function separateCosts(float $totalTimeMs, int $currentRows, ComplexityClass $complexity): array
    {
        // Constant/logarithmic lookups: 100% fixed, 0% variable (no row-proportional cost)
        if ($complexity === ComplexityClass::Constant || $complexity === ComplexityClass::Logarithmic) {
            return ['fixed_ms' => $totalTimeMs, 'variable_ms' => 0.0];
        }

        // For scan-based complexity, estimate the fixed overhead fraction
        // based on current table size. Smaller tables = higher fixed overhead ratio.
        $fixedRatio = match (true) {
            $currentRows < 100 => 0.95,
            $currentRows < 1_000 => 0.80,
            $currentRows < 10_000 => 0.50,
            default => 0.10,
        };

        return [
            'fixed_ms' => round($totalTimeMs * $fixedRatio, 4),
            'variable_ms' => round(max($totalTimeMs * (1.0 - $fixedRatio), 0.0), 4),
        ];
    }

    /**
     * Compute page-based growth factor for linear/scan access types.
     *
     * InnoDB reads data in 16KB pages (~100 rows per page at average row size).
     * Full scans scale by pages read, not individual rows, for realistic I/O modeling.
     */
    private function computePageBasedFactor(int $currentRows, int $targetRows): float
    {
        $rowsPerPage = 100;
        $currentPages = max((int) ceil($currentRows / $rowsPerPage), 1);
        $targetPages = (int) ceil($targetRows / $rowsPerPage);

        return $targetPages / $currentPages;
    }

    /**
     * Compute growth factor using real asymptotic formulas.
     *
     * Used for non-scan complexity classes (constant, logarithmic, sub-linear).
     */
    private function computeGrowthFactor(ComplexityClass $complexity, int $currentRows, int $targetRows): float
    {
        $factor = $targetRows / max($currentRows, 1);

        return match ($complexity) {
            // O(1): constant — no growth
            ComplexityClass::Constant => 1.0,

            // O(log n): logarithmic — log(N) / log(n)
            ComplexityClass::Logarithmic => max(1.0, log($targetRows, 2) / log(max($currentRows, 2), 2)),

            // O(log n + k): sub-linear — geometric mean of log and linear
            ComplexityClass::LogRange => max(1.0, sqrt($factor)),

            // O(n): linear — N/n (page-based path used instead for scan types)
            ComplexityClass::Linear => $factor,

            // O(n log n): linearithmic — (N/n) * log(N)/log(n)
            ComplexityClass::Linearithmic => $factor * max(1.0, log($targetRows, 2) / log(max($currentRows, 2), 2)),

            // O(n²): quadratic — (N/n)²
            ComplexityClass::Quadratic => $factor * $factor,
        };
    }

    /**
     * Human-readable model label for display.
     */
    private function modelLabel(ComplexityClass $complexity): string
    {
        return match ($complexity) {
            ComplexityClass::Constant => 'stable',
            ComplexityClass::Logarithmic => 'logarithmic',
            ComplexityClass::LogRange => 'sub-linear',
            ComplexityClass::Linear => 'linear',
            ComplexityClass::Linearithmic => 'linearithmic',
            ComplexityClass::Quadratic => 'quadratic',
        };
    }

    /**
     * Assess scalability risk based on complexity, table size, and projected impact.
     *
     * Size-aware: table scans on small tables are not dangerous.
     */
    private function assessRisk(ComplexityClass $complexity, array $metrics, float $maxProjectedTime): string
    {
        // Zero-row const: always LOW
        if ($metrics['is_zero_row_const'] ?? false) {
            return 'LOW';
        }

        // Rows examined = 0: auto-correct to LOW (consistency rule)
        if (($metrics['rows_examined'] ?? 0) === 0 && ($metrics['rows_returned'] ?? 0) === 0) {
            return 'LOW';
        }

        if ($complexity === ComplexityClass::Quadratic) {
            return 'HIGH';
        }

        if ($complexity === ComplexityClass::Constant) {
            return 'LOW';
        }

        if ($complexity === ComplexityClass::Logarithmic || $complexity === ComplexityClass::LogRange) {
            return $maxProjectedTime > 10_000 ? 'MEDIUM' : 'LOW';
        }

        // Linear / Linearithmic — size-aware + intent-aware risk assessment
        $hasTableScan = $metrics['has_table_scan'] ?? false;
        $rowsExamined = $metrics['rows_examined'] ?? 0;
        $currentRows = $metrics['current_rows'] ?? $rowsExamined;
        $isIntentionalScan = $metrics['is_intentional_scan'] ?? false;

        // Intentional full scan (no WHERE/JOIN/GROUP BY): O(n) is expected behavior.
        // Risk is LOW for small tables, MEDIUM for large — but never HIGH.
        if ($isIntentionalScan) {
            return $currentRows > 100_000 ? 'MEDIUM' : 'LOW';
        }

        if ($rowsExamined > 100_000) {
            return 'HIGH';
        }

        if ($hasTableScan) {
            return match (true) {
                $currentRows <= 1_000 => 'LOW',
                $currentRows <= 100_000 => 'MEDIUM',
                default => 'HIGH',
            };
        }

        return 'MEDIUM';
    }

    /**
     * Estimate how execution time changes at different LIMIT values.
     *
     * Three regimes:
     *   1. Already has early termination: project linearly from current LIMIT
     *   2. Intentional scan without ORDER BY: LIMIT enables sequential early termination
     *   3. ORDER BY or other blocking ops: LIMIT does not reduce read cost
     *
     * @return array<int, array{projected_time_ms: float, note: string}>
     */
    private function estimateLimitSensitivity(array $metrics, float $currentTimeMs, ?string $sql = null): array
    {
        $hasEarlyTermination = $metrics['has_early_termination'] ?? false;
        $rowsReturned = max($metrics['rows_returned'] ?? 1, 1);
        $isIntentionalScan = $metrics['is_intentional_scan'] ?? false;
        $hasFilesort = $metrics['has_filesort'] ?? false;
        $hasOrderBy = $sql !== null && (bool) preg_match('/\bORDER\s+BY\b/i', $sql);

        // Intentional scans without ORDER BY: LIMIT enables sequential early termination
        // MySQL reads rows sequentially and stops after LIMIT N rows.
        $canSimulateEarlyTermination = $isIntentionalScan && ! $hasOrderBy && ! $hasFilesort;

        $limits = [100, 500, 1000];
        $sensitivity = [];

        foreach ($limits as $limit) {
            if ($hasEarlyTermination) {
                $factor = $limit / $rowsReturned;
                $projectedTime = round($currentTimeMs * max($factor, 1.0), 2);
                $note = $factor > 10 ? 'May trigger plan change' : 'Linear scaling expected';
            } elseif ($canSimulateEarlyTermination) {
                $factor = min(1.0, $limit / $rowsReturned);
                $projectedTime = round($currentTimeMs * max($factor, 0.01), 2);
                $note = 'Sequential scan stops early (no ORDER BY)';
            } else {
                $projectedTime = round($currentTimeMs, 2);
                $note = ($hasOrderBy || $hasFilesort)
                    ? 'LIMIT does not reduce work (ORDER BY requires full scan + sort)'
                    : 'LIMIT does not reduce work (no early termination)';
            }

            $sensitivity[$limit] = [
                'projected_time_ms' => $projectedTime,
                'note' => $note,
            ];
        }

        return $sensitivity;
    }

    /**
     * Sub-classify linear complexity into actionable categories.
     *
     * EXPORT_LINEAR: intentional full dataset retrieval (no WHERE/JOIN)
     * ANALYTICAL_LINEAR: aggregation/grouping forces linear scan
     * INDEX_MISSED_LINEAR: table scan with WHERE clause (index should exist)
     * PATHOLOGICAL_LINEAR: everything else (high fanout, weedout, etc.)
     *
     * @param  array<string, mixed>  $metrics
     */
    private function classifyLinearSubtype(array $metrics, ?string $sql = null): string
    {
        if ($metrics['is_intentional_scan'] ?? false) {
            return 'EXPORT_LINEAR';
        }

        if ($sql !== null) {
            $hasGroupBy = (bool) preg_match('/\bGROUP\s+BY\b/i', $sql);
            $hasAgg = (bool) preg_match('/\b(COUNT|SUM|AVG|MIN|MAX)\s*\(/i', $sql);
            if ($hasGroupBy || $hasAgg) {
                return 'ANALYTICAL_LINEAR';
            }
        }

        if (($metrics['has_table_scan'] ?? false) && ! ($metrics['is_intentional_scan'] ?? false)) {
            return 'INDEX_MISSED_LINEAR';
        }

        return 'PATHOLOGICAL_LINEAR';
    }
}
