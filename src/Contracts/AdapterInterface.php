<?php

declare(strict_types=1);

namespace QuerySentinel\Contracts;

use QuerySentinel\Support\Report;

/**
 * Adapter contract for transforming various inputs into analyzed reports.
 *
 * Each adapter accepts a specific input type (SQL string, Builder, etc.),
 * transforms it to raw SQL, and delegates to the core AnalyzerInterface.
 */
interface AdapterInterface
{
    /**
     * Analyze the given input and produce a diagnostic report.
     */
    public function analyze(mixed $input): Report;
}
