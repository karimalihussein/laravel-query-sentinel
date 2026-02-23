<?php

declare(strict_types=1);

namespace QuerySentinel\Contracts;

use QuerySentinel\Support\Report;

/**
 * Core query analyzer contract.
 *
 * Operates on raw SQL strings only. Framework-agnostic.
 * All adapters ultimately delegate to an AnalyzerInterface implementation.
 */
interface AnalyzerInterface
{
    /**
     * Analyze a SQL query and produce a diagnostic report.
     */
    public function analyze(string $sql, string $mode = 'sql'): Report;
}
