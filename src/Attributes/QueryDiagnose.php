<?php

declare(strict_types=1);

namespace QuerySentinel\Attributes;

use Attribute;

/**
 * Mark a method for automatic query profiling.
 *
 * When placed on a controller method (with middleware) or a service method
 * (with container proxy), all database queries executed during the method
 * call will be captured, analyzed, and logged.
 *
 * Usage:
 *
 *   #[QueryDiagnose]
 *   public function index(LeadFilterDTO $dto) { ... }
 *
 *   #[QueryDiagnose(thresholdMs: 50, sampleRate: 0.10, logChannel: 'performance')]
 *   public function search(string $query) { ... }
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class QueryDiagnose
{
    /**
     * @param  int  $thresholdMs  Minimum cumulative query time to trigger logging (0 = always log)
     * @param  float  $sampleRate  Fraction of invocations to profile (0.0–1.0, 1.0 = always)
     * @param  bool  $failOnCritical  Throw PerformanceViolationException on critical findings
     * @param  string  $logChannel  Laravel log channel for structured output
     */
    public function __construct(
        public readonly int $thresholdMs = 0,
        public readonly float $sampleRate = 1.0,
        public readonly bool $failOnCritical = false,
        public readonly string $logChannel = 'performance',
    ) {}
}
