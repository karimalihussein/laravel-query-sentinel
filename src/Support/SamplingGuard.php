<?php

declare(strict_types=1);

namespace QuerySentinel\Support;

/**
 * Probabilistic sampling guard for production-safe profiling.
 *
 * Determines whether a given invocation should be profiled based on
 * the method-level sample rate and the global sample rate.
 *
 * Effective rate = min(methodRate, globalRate).
 *   - 1.0 = always profile
 *   - 0.0 = never profile
 *   - 0.05 = profile ~5% of invocations
 */
final class SamplingGuard
{
    /**
     * Determine if this invocation should be profiled.
     *
     * @param  float  $methodRate  Per-method sample rate from #[QueryDiagnose]
     * @param  float  $globalRate  Global sample rate from config
     */
    public static function shouldProfile(float $methodRate, float $globalRate = 1.0): bool
    {
        $effectiveRate = min($methodRate, $globalRate);

        if ($effectiveRate >= 1.0) {
            return true;
        }

        if ($effectiveRate <= 0.0) {
            return false;
        }

        return (mt_rand() / mt_getrandmax()) < $effectiveRate;
    }
}
