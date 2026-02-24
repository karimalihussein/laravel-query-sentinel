<?php

declare(strict_types=1);

namespace QuerySentinel\Analyzers;

use QuerySentinel\Enums\Severity;
use QuerySentinel\Support\BaselineStore;
use QuerySentinel\Support\Finding;

/**
 * Phase 9: Regression & Baseline System.
 *
 * Stores analysis snapshots and detects performance regressions over time
 * by comparing current metrics against historical baselines. Identifies
 * score drops, execution time increases, row examination spikes, and
 * overall performance trends.
 */
final class RegressionBaselineAnalyzer
{
    private BaselineStore $store;

    private int $maxHistory;

    private float $scoreWarningThreshold;

    private float $scoreCriticalThreshold;

    private float $timeWarningThreshold;

    private float $timeCriticalThreshold;

    /** Absolute time delta (ms) required in addition to percentage threshold. */
    private float $absoluteTimeThreshold;

    /** Absolute score delta (points) required in addition to percentage threshold. */
    private float $absoluteScoreThreshold;

    /** Ignore time deltas below this floor (ms) — sub-millisecond noise filter. */
    private float $noiseFloorMs;

    /** Suppress all time-based warnings when baseline average is below this (ms). */
    private float $minimumMeasurableMs;

    public function __construct(
        BaselineStore $store,
        int $maxHistory = 10,
        float $scoreWarningThreshold = 10.0,
        float $scoreCriticalThreshold = 25.0,
        float $timeWarningThreshold = 50.0,
        float $timeCriticalThreshold = 200.0,
        float $absoluteTimeThreshold = 5.0,
        float $absoluteScoreThreshold = 5.0,
        float $noiseFloorMs = 3.0,
        float $minimumMeasurableMs = 5.0,
    ) {
        $this->store = $store;
        $this->maxHistory = $maxHistory;
        $this->scoreWarningThreshold = $scoreWarningThreshold;
        $this->scoreCriticalThreshold = $scoreCriticalThreshold;
        $this->timeWarningThreshold = $timeWarningThreshold;
        $this->timeCriticalThreshold = $timeCriticalThreshold;
        $this->absoluteTimeThreshold = $absoluteTimeThreshold;
        $this->absoluteScoreThreshold = $absoluteScoreThreshold;
        $this->noiseFloorMs = $noiseFloorMs;
        $this->minimumMeasurableMs = $minimumMeasurableMs;
    }

    /**
     * Analyze a query's current metrics against its historical baseline.
     *
     * Detects regressions, improvements, and trend direction. Auto-saves the
     * current snapshot after analysis for future comparisons.
     *
     * @param  string  $sql  Raw SQL (used for hashing)
     * @param  array<string, mixed>  $currentMetrics  Current analysis snapshot data
     * @return array{regression: array<string, mixed>, findings: Finding[]}
     */
    public function analyze(string $sql, array $currentMetrics): array
    {
        $queryHash = $this->normalizeAndHash($sql);
        $history = $this->store->history($queryHash, $this->maxHistory);
        $findings = [];

        $hasBaseline = count($history) > 0;

        $regressions = [];
        $improvements = [];
        $trend = 'stable';

        if ($hasBaseline) {
            // Calculate baseline averages
            $scoreAvg = $this->averageMetric($history, 'composite_score');
            $timeAvg = $this->averageMetric($history, 'execution_time_ms');
            $rowsAvg = $this->averageMetric($history, 'rows_examined');

            $currentScore = (float) ($currentMetrics['composite_score'] ?? 0);
            $currentTime = (float) ($currentMetrics['execution_time_ms'] ?? 0);
            $currentRows = (float) ($currentMetrics['rows_examined'] ?? 0);

            // Score regression: lower score is worse, so positive change_pct means regression
            $scoreRegression = ($scoreAvg - $currentScore) / max($scoreAvg, 1.0) * 100;

            // Time regression: higher time is worse, so positive change_pct means regression
            $timeRegression = ($currentTime - $timeAvg) / max($timeAvg, 1.0) * 100;

            // Rows regression: higher rows is worse, so positive change_pct means regression
            $rowsRegression = ($currentRows - $rowsAvg) / max($rowsAvg, 1.0) * 100;

            // Composite score analysis
            $this->classifyMetric(
                'composite_score',
                $scoreAvg,
                $currentScore,
                $scoreRegression,
                $this->scoreWarningThreshold,
                $this->scoreCriticalThreshold,
                $regressions,
                $improvements,
                $findings,
            );

            // Cold cache normalization: suppress time classification when cache state changed
            // Cold→warm improvement is cache warming, not genuine; warm→cold regression is transient
            $baselineColdCount = 0;
            foreach ($history as $snap) {
                if ($snap['is_cold_cache'] ?? false) {
                    $baselineColdCount++;
                }
            }
            $baselineMostlyCold = $baselineColdCount > count($history) / 2;
            $currentCold = (bool) ($currentMetrics['is_cold_cache'] ?? false);

            $skipTimeClassification = ($baselineMostlyCold && ! $currentCold && $timeRegression < 0)
                || (! $baselineMostlyCold && $currentCold && $timeRegression > 0);

            // Execution time analysis — normalized by per-row cost when rows changed.
            // If time increased proportionally to rows growth → data volume growth, not regression.
            if (! $skipTimeClassification) {
                $this->classifyExecutionTime(
                    $timeAvg,
                    $currentTime,
                    $timeRegression,
                    $rowsAvg,
                    $currentRows,
                    $regressions,
                    $improvements,
                    $findings,
                );
            }

            // Plan change detection: access type or indexes changed
            $lastSnapshot = end($history);
            if ($lastSnapshot !== false) {
                $this->classifyPlanChange(
                    $lastSnapshot,
                    $currentMetrics,
                    $regressions,
                    $improvements,
                    $findings,
                );
            }

            // Rows examined analysis — normalized by per-row performance.
            // If rows grew but time_per_row is stable/improved → data growth, not regression.
            $this->classifyRowsExamined(
                $rowsAvg,
                $currentRows,
                $rowsRegression,
                $timeAvg,
                $currentTime,
                $regressions,
                $improvements,
                $findings,
            );

            // Trend detection using last 3 snapshots
            $trend = $this->detectTrend($history);
        }

        // Build the current snapshot and auto-save
        $snapshot = $this->buildSnapshot($queryHash, $currentMetrics);
        $this->store->save($queryHash, $snapshot);

        // Add trend finding if degrading
        if ($trend === 'degrading') {
            $findings[] = new Finding(
                severity: Severity::Warning,
                category: 'regression',
                title: 'Degrading performance trend',
                description: 'The last 3 snapshots show a consistent decline in composite score, indicating a degrading performance trend.',
                recommendation: 'Investigate recent schema or query changes that may have caused the regression.',
                metadata: ['trend' => 'degrading'],
            );
        }

        return [
            'regression' => [
                'has_baseline' => $hasBaseline,
                'baseline_count' => count($history),
                'regressions' => $regressions,
                'improvements' => $improvements,
                'trend' => $trend,
            ],
            'findings' => $findings,
        ];
    }

    /**
     * Classify a metric change as regression or improvement and generate findings.
     *
     * @param  array<int, array<string, mixed>>  $regressions
     * @param  array<int, array<string, mixed>>  $improvements
     * @param  Finding[]  $findings
     */
    private function classifyMetric(
        string $metric,
        float $baselineValue,
        float $currentValue,
        float $regressionPct,
        float $warningThreshold,
        float $criticalThreshold,
        array &$regressions,
        array &$improvements,
        array &$findings,
    ): void {
        $roundedPct = round($regressionPct, 2);
        $absoluteDelta = abs($currentValue - $baselineValue);

        if ($regressionPct > 0) {
            // Noise filter: suppress sub-millisecond timing jitter for time metrics
            if ($metric === 'execution_time_ms' && $absoluteDelta < $this->noiseFloorMs) {
                return;
            }

            // Suppress time-based warnings when baseline is below minimum measurable
            if ($metric === 'execution_time_ms' && $baselineValue < $this->minimumMeasurableMs) {
                return;
            }

            // Dual-threshold: percentage AND absolute delta must both exceed thresholds
            $absoluteThreshold = match ($metric) {
                'execution_time_ms' => $this->absoluteTimeThreshold,
                'composite_score' => $this->absoluteScoreThreshold,
                default => 0.0, // rows_examined has no absolute threshold
            };

            if ($absoluteThreshold > 0.0 && $absoluteDelta < $absoluteThreshold) {
                return;
            }

            // Regression detected
            $severity = 'warning';
            $findingSeverity = Severity::Warning;

            if ($regressionPct > $criticalThreshold) {
                $severity = 'critical';
                $findingSeverity = Severity::Critical;
            }

            // Only report if above warning threshold
            if ($regressionPct >= $warningThreshold) {
                $regressions[] = [
                    'metric' => $metric,
                    'baseline_value' => round($baselineValue, 2),
                    'current_value' => round($currentValue, 2),
                    'change_pct' => $roundedPct,
                    'severity' => $severity,
                ];

                $findings[] = new Finding(
                    severity: $findingSeverity,
                    category: 'regression',
                    title: sprintf('Regression in %s: %.1f%% degradation', $metric, $roundedPct),
                    description: sprintf(
                        '%s changed from baseline average %.2f to current %.2f (%.1f%% %s).',
                        $metric,
                        $baselineValue,
                        $currentValue,
                        abs($roundedPct),
                        $metric === 'composite_score' ? 'decrease' : 'increase',
                    ),
                    recommendation: $this->recommendationForMetric($metric),
                    metadata: [
                        'metric' => $metric,
                        'baseline_value' => round($baselineValue, 2),
                        'current_value' => round($currentValue, 2),
                        'change_pct' => $roundedPct,
                    ],
                );
            }
        } elseif ($regressionPct < 0) {
            // Improvement detected
            $improvements[] = [
                'metric' => $metric,
                'baseline_value' => round($baselineValue, 2),
                'current_value' => round($currentValue, 2),
                'change_pct' => $roundedPct,
            ];
        }
    }

    /**
     * Classify execution time changes with per-row normalization.
     *
     * If rows_examined changed significantly, compare time_per_row instead of
     * absolute time. A query scanning 2x more rows taking 2x more time is
     * DATA_VOLUME_GROWTH, not PERFORMANCE_DEGRADATION.
     *
     * @param  array<int, array<string, mixed>>  $regressions
     * @param  array<int, array<string, mixed>>  $improvements
     * @param  Finding[]  $findings
     */
    private function classifyExecutionTime(
        float $baselineTime,
        float $currentTime,
        float $timeRegressionPct,
        float $baselineRows,
        float $currentRows,
        array &$regressions,
        array &$improvements,
        array &$findings,
    ): void {
        $absoluteDelta = abs($currentTime - $baselineTime);

        // Noise filter
        if ($absoluteDelta < $this->noiseFloorMs) {
            return;
        }

        // Suppress when baseline is below minimum measurable
        if ($baselineTime < $this->minimumMeasurableMs) {
            return;
        }

        // Dual-threshold: percentage AND absolute delta
        if ($absoluteDelta < $this->absoluteTimeThreshold) {
            return;
        }

        // Improvement path
        if ($timeRegressionPct <= 0) {
            if ($timeRegressionPct < -$this->timeWarningThreshold) {
                $improvements[] = [
                    'metric' => 'execution_time_ms',
                    'baseline_value' => round($baselineTime, 2),
                    'current_value' => round($currentTime, 2),
                    'change_pct' => round($timeRegressionPct, 2),
                ];
            }

            return;
        }

        // Check if regression is below warning threshold
        if ($timeRegressionPct < $this->timeWarningThreshold) {
            return;
        }

        // Determine if this is data-growth-driven or a real per-row degradation.
        // If rows changed by >20%, normalize by per-row cost.
        $rowsChangePct = $baselineRows > 0
            ? (($currentRows - $baselineRows) / $baselineRows) * 100
            : 0.0;

        if ($baselineRows > 0 && $currentRows > 0 && abs($rowsChangePct) > 20.0) {
            $baselineTimePerRow = $baselineTime / $baselineRows;
            $currentTimePerRow = $currentTime / $currentRows;
            $perRowDegradation = $baselineTimePerRow > 0
                ? (($currentTimePerRow - $baselineTimePerRow) / $baselineTimePerRow) * 100
                : 0.0;

            // Per-row cost stable or improved → data volume growth
            if ($perRowDegradation < 25.0) {
                if ($timeRegressionPct >= 50.0) {
                    $findings[] = new Finding(
                        severity: Severity::Info,
                        category: 'regression',
                        title: sprintf('Data growth: execution_time increased %.1f%%', $timeRegressionPct),
                        description: sprintf(
                            'execution_time_ms increased from %.2fms to %.2fms (%.1f%%), but rows_examined also grew %.1f%% (%.0f → %.0f). Per-row cost is stable (%.4fms → %.4fms). This is data volume growth, not performance degradation.',
                            $baselineTime,
                            $currentTime,
                            $timeRegressionPct,
                            $rowsChangePct,
                            $baselineRows,
                            $currentRows,
                            $baselineTimePerRow,
                            $currentTimePerRow,
                        ),
                        recommendation: 'Monitor table growth. Consider partitioning or pagination (chunk/cursor) for large table scans.',
                        metadata: [
                            'metric' => 'execution_time_ms',
                            'classification' => 'data_growth',
                            'baseline_value' => round($baselineTime, 2),
                            'current_value' => round($currentTime, 2),
                            'change_pct' => round($timeRegressionPct, 2),
                            'rows_change_pct' => round($rowsChangePct, 2),
                            'baseline_time_per_row' => round($baselineTimePerRow, 6),
                            'current_time_per_row' => round($currentTimePerRow, 6),
                        ],
                    );
                }

                return;
            }
        }

        // Real performance degradation (absolute or per-row cost worsened)
        $severity = $timeRegressionPct > $this->timeCriticalThreshold ? 'critical' : 'warning';
        $findingSeverity = $timeRegressionPct > $this->timeCriticalThreshold ? Severity::Critical : Severity::Warning;

        $classification = abs($rowsChangePct) > 20.0 ? 'performance_degradation' : 'performance_degradation';

        $regressions[] = [
            'metric' => 'execution_time_ms',
            'baseline_value' => round($baselineTime, 2),
            'current_value' => round($currentTime, 2),
            'change_pct' => round($timeRegressionPct, 2),
            'severity' => $severity,
            'classification' => $classification,
        ];

        $findings[] = new Finding(
            severity: $findingSeverity,
            category: 'regression',
            title: sprintf('Regression in execution_time_ms: %.1f%% degradation', $timeRegressionPct),
            description: sprintf(
                'execution_time_ms changed from baseline average %.2fms to current %.2fms (%.1f%% increase).',
                $baselineTime,
                $currentTime,
                abs($timeRegressionPct),
            ),
            recommendation: $this->recommendationForMetric('execution_time_ms'),
            metadata: [
                'metric' => 'execution_time_ms',
                'classification' => $classification,
                'baseline_value' => round($baselineTime, 2),
                'current_value' => round($currentTime, 2),
                'change_pct' => round($timeRegressionPct, 2),
            ],
        );
    }

    /**
     * Classify rows_examined changes with per-row performance normalization.
     *
     * Distinguishes data growth (rows increased but per-row cost stable) from
     * real performance regression (per-row cost worsened).
     *
     * @param  array<int, array<string, mixed>>  $regressions
     * @param  array<int, array<string, mixed>>  $improvements
     * @param  Finding[]  $findings
     */
    private function classifyRowsExamined(
        float $baselineRows,
        float $currentRows,
        float $rowsRegressionPct,
        float $baselineTime,
        float $currentTime,
        array &$regressions,
        array &$improvements,
        array &$findings,
    ): void {
        if ($rowsRegressionPct <= 0) {
            if ($rowsRegressionPct < 0) {
                $improvements[] = [
                    'metric' => 'rows_examined',
                    'baseline_value' => round($baselineRows, 2),
                    'current_value' => round($currentRows, 2),
                    'change_pct' => round($rowsRegressionPct, 2),
                ];
            }

            return;
        }

        // Compute per-row performance: time_per_row = time / rows
        $baselineTimePerRow = $baselineRows > 0 ? $baselineTime / $baselineRows : 0.0;
        $currentTimePerRow = $currentRows > 0 ? $currentTime / $currentRows : 0.0;

        // Per-row degradation percentage
        $perRowDegradation = $baselineTimePerRow > 0
            ? (($currentTimePerRow - $baselineTimePerRow) / $baselineTimePerRow) * 100
            : 0.0;

        // If rows grew but per-row performance is stable or better → data growth event, not regression
        if ($perRowDegradation < 25.0) {
            // Log as info-level data growth event, not a regression
            if ($rowsRegressionPct >= 50.0) {
                $findings[] = new Finding(
                    severity: Severity::Info,
                    category: 'regression',
                    title: sprintf('Data growth: rows_examined increased %.1f%%', $rowsRegressionPct),
                    description: sprintf(
                        'rows_examined increased from %.0f to %.0f (%.1f%%), but per-row performance is stable (%.4fms/row → %.4fms/row). This is data volume growth, not performance degradation.',
                        $baselineRows,
                        $currentRows,
                        $rowsRegressionPct,
                        $baselineTimePerRow,
                        $currentTimePerRow,
                    ),
                    recommendation: 'Monitor table growth. Consider partitioning or archiving if table size continues to increase.',
                    metadata: [
                        'metric' => 'rows_examined',
                        'classification' => 'data_growth',
                        'baseline_value' => round($baselineRows, 2),
                        'current_value' => round($currentRows, 2),
                        'change_pct' => round($rowsRegressionPct, 2),
                        'baseline_time_per_row' => round($baselineTimePerRow, 6),
                        'current_time_per_row' => round($currentTimePerRow, 6),
                    ],
                );
            }

            return;
        }

        // Per-row performance degraded → real regression
        $severity = $perRowDegradation >= 100.0 ? 'critical' : 'warning';
        $findingSeverity = $perRowDegradation >= 100.0 ? Severity::Critical : Severity::Warning;

        $regressions[] = [
            'metric' => 'rows_examined',
            'baseline_value' => round($baselineRows, 2),
            'current_value' => round($currentRows, 2),
            'change_pct' => round($rowsRegressionPct, 2),
            'severity' => $severity,
            'classification' => 'performance_degradation',
            'per_row_degradation_pct' => round($perRowDegradation, 2),
        ];

        $findings[] = new Finding(
            severity: $findingSeverity,
            category: 'regression',
            title: sprintf('Regression in rows_examined: %.1f%% increase with %.1f%% per-row degradation', $rowsRegressionPct, $perRowDegradation),
            description: sprintf(
                'rows_examined changed from %.0f to %.0f (%.1f%% increase) and per-row performance degraded from %.4fms/row to %.4fms/row (%.1f%% slower). This indicates actual performance degradation, not just data growth.',
                $baselineRows,
                $currentRows,
                $rowsRegressionPct,
                $baselineTimePerRow,
                $currentTimePerRow,
                $perRowDegradation,
            ),
            recommendation: 'Investigate index regression or query plan changes. The per-row cost increase suggests degraded execution efficiency, not just larger data.',
            metadata: [
                'metric' => 'rows_examined',
                'classification' => 'performance_degradation',
                'baseline_value' => round($baselineRows, 2),
                'current_value' => round($currentRows, 2),
                'change_pct' => round($rowsRegressionPct, 2),
                'per_row_degradation_pct' => round($perRowDegradation, 2),
            ],
        );
    }

    /**
     * Detect plan changes: access type or index set changed between baseline and current.
     *
     * Access type downgrades (e.g., const_row → table_scan) are regressions.
     * Upgrades (e.g., table_scan → single_row_lookup) are improvements.
     * Index set changes without access type change are noted as info.
     *
     * @param  array<string, mixed>  $lastSnapshot
     * @param  array<string, mixed>  $currentMetrics
     * @param  array<int, array<string, mixed>>  $regressions
     * @param  array<int, array<string, mixed>>  $improvements
     * @param  Finding[]  $findings
     */
    private function classifyPlanChange(
        array $lastSnapshot,
        array $currentMetrics,
        array &$regressions,
        array &$improvements,
        array &$findings,
    ): void {
        $previousAccessType = (string) ($lastSnapshot['access_type'] ?? 'unknown');
        $currentAccessType = (string) ($currentMetrics['primary_access_type'] ?? 'unknown');

        /** @var string[] $previousIndexes */
        $previousIndexes = $lastSnapshot['indexes_used'] ?? [];
        /** @var string[] $currentIndexes */
        $currentIndexes = $currentMetrics['indexes_used'] ?? [];

        $accessTypeChanged = $previousAccessType !== $currentAccessType
            && $previousAccessType !== 'unknown'
            && $currentAccessType !== 'unknown';
        $indexesChanged = $previousIndexes !== $currentIndexes;

        if (! $accessTypeChanged && ! $indexesChanged) {
            return;
        }

        $isDowngrade = $accessTypeChanged && $this->isAccessTypeDowngrade($previousAccessType, $currentAccessType);
        $isUpgrade = $accessTypeChanged && $this->isAccessTypeDowngrade($currentAccessType, $previousAccessType);

        if ($isDowngrade) {
            $regressions[] = [
                'metric' => 'plan_change',
                'baseline_value' => $previousAccessType,
                'current_value' => $currentAccessType,
                'change_pct' => 0,
                'severity' => 'warning',
                'classification' => 'plan_change',
            ];

            $findings[] = new Finding(
                severity: Severity::Warning,
                category: 'regression',
                title: sprintf('Plan change: %s -> %s', $previousAccessType, $currentAccessType),
                description: sprintf(
                    'Access type changed from %s to %s. Indexes changed from [%s] to [%s]. This indicates a query plan regression.',
                    $previousAccessType,
                    $currentAccessType,
                    implode(', ', $previousIndexes),
                    implode(', ', $currentIndexes),
                ),
                recommendation: 'Run ANALYZE TABLE to update statistics. Check for recent schema changes, dropped indexes, or data distribution shifts.',
                metadata: [
                    'metric' => 'plan_change',
                    'classification' => 'plan_change',
                    'previous_access_type' => $previousAccessType,
                    'current_access_type' => $currentAccessType,
                    'previous_indexes' => $previousIndexes,
                    'current_indexes' => $currentIndexes,
                ],
            );
        } elseif ($isUpgrade) {
            $improvements[] = [
                'metric' => 'plan_change',
                'baseline_value' => $previousAccessType,
                'current_value' => $currentAccessType,
                'change_pct' => 0,
                'classification' => 'plan_upgrade',
            ];
        }
    }

    /**
     * Determine if an access type change is a downgrade.
     *
     * Access type quality order (best to worst):
     *   zero_row_const > const_row > single_row_lookup > range_scan > table_scan
     */
    private function isAccessTypeDowngrade(string $from, string $to): bool
    {
        $rank = [
            'zero_row_const' => 0,
            'const_row' => 1,
            'single_row_lookup' => 2,
            'range_scan' => 3,
            'table_scan' => 4,
        ];

        $fromRank = $rank[$from] ?? 3;
        $toRank = $rank[$to] ?? 3;

        return $toRank > $fromRank;
    }

    /**
     * Detect trend direction from the last 3 snapshots' composite scores.
     *
     * @param  array<int, array<string, mixed>>  $history
     */
    private function detectTrend(array $history): string
    {
        if (count($history) < 3) {
            return 'stable';
        }

        // Take last 3 snapshots
        $recent = array_slice($history, -3);
        $scores = array_map(
            static fn (array $snapshot): float => (float) ($snapshot['composite_score'] ?? 0),
            $recent,
        );

        // Check degrading: each score is lower (worse) than the previous
        $isDegrading = ($scores[0] > $scores[1]) && ($scores[1] > $scores[2]);

        // Check improving: each score is higher (better) than the previous
        $isImproving = ($scores[0] < $scores[1]) && ($scores[1] < $scores[2]);

        if ($isDegrading) {
            return 'degrading';
        }

        if ($isImproving) {
            return 'improving';
        }

        return 'stable';
    }

    /**
     * Calculate the average of a metric across history snapshots.
     *
     * @param  array<int, array<string, mixed>>  $history
     */
    private function averageMetric(array $history, string $metric): float
    {
        if (empty($history)) {
            return 0.0;
        }

        $sum = 0.0;
        foreach ($history as $snapshot) {
            $sum += (float) ($snapshot[$metric] ?? 0);
        }

        return $sum / count($history);
    }

    /**
     * Build a snapshot array from current metrics.
     *
     * @param  array<string, mixed>  $currentMetrics
     * @return array<string, mixed>
     */
    private function buildSnapshot(string $queryHash, array $currentMetrics): array
    {
        /** @var string[] $indexesUsed */
        $indexesUsed = $currentMetrics['indexes_used'] ?? [];

        /** @var array<string, int> $findingCounts */
        $findingCounts = $currentMetrics['finding_counts'] ?? [];

        $executionTimeMs = (float) ($currentMetrics['execution_time_ms'] ?? 0);
        $rowsExamined = (int) ($currentMetrics['rows_examined'] ?? 0);
        $timePerRow = $rowsExamined > 0 ? $executionTimeMs / $rowsExamined : 0.0;

        return [
            'query_hash' => $queryHash,
            'timestamp' => date('c'),
            'composite_score' => (float) ($currentMetrics['composite_score'] ?? 0),
            'grade' => (string) ($currentMetrics['grade'] ?? 'N/A'),
            'execution_time_ms' => $executionTimeMs,
            'rows_examined' => $rowsExamined,
            'time_per_row' => round($timePerRow, 8),
            'complexity' => (string) ($currentMetrics['complexity'] ?? 'unknown'),
            'access_type' => (string) ($currentMetrics['primary_access_type'] ?? 'unknown'),
            'indexes_used' => $indexesUsed,
            'finding_counts' => $findingCounts,
            'table_size' => (int) ($currentMetrics['rows_returned'] ?? $rowsExamined),
            'buffer_pool_utilization' => (float) ($currentMetrics['buffer_pool_utilization'] ?? 0.0),
            'is_cold_cache' => (bool) ($currentMetrics['is_cold_cache'] ?? false),
        ];
    }

    private function normalizeAndHash(string $sql): string
    {
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $sql) ?? $sql));

        return hash('sha256', $normalized);
    }

    private function recommendationForMetric(string $metric): string
    {
        return match ($metric) {
            'composite_score' => 'Review recent query or schema changes. Run EXPLAIN ANALYZE to identify the root cause of score degradation.',
            'execution_time_ms' => 'Check for missing indexes, increased data volume, or lock contention that may have slowed execution.',
            'rows_examined' => 'Verify index coverage and filter selectivity. A spike in rows examined often indicates index regression or data growth.',
            default => 'Investigate the metric change against recent deployments or data changes.',
        };
    }
}
