<?php

declare(strict_types=1);

namespace QuerySentinel\Contracts;

interface ScoringEngineInterface
{
    /**
     * Calculate performance scores from parsed metrics.
     *
     * @param  array<string, mixed>  $metrics
     * @return array{composite_score: float, grade: string, breakdown: array<string, mixed>}
     */
    public function score(array $metrics): array;
}
