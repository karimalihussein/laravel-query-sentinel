<?php

declare(strict_types=1);

namespace QuerySentinel\Support;

/**
 * Threshold guard for filtering noise from profiling logs.
 *
 * Prevents logging for fast invocations that don't warrant attention.
 * Uses the effective threshold: max(methodThreshold, globalDefault).
 */
final class ThresholdGuard
{
    /**
     * Determine if the profiling result should be logged.
     *
     * @param  float  $cumulativeTimeMs  Total query time from profiling
     * @param  int  $methodThreshold  Per-method threshold from #[QueryDiagnose]
     * @param  int  $globalDefault  Global default threshold from config
     */
    public static function shouldLog(
        float $cumulativeTimeMs,
        int $methodThreshold = 0,
        int $globalDefault = 0,
    ): bool {
        $effectiveThreshold = max($methodThreshold, $globalDefault);

        if ($effectiveThreshold <= 0) {
            return true;
        }

        return $cumulativeTimeMs >= (float) $effectiveThreshold;
    }
}
