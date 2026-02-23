<?php

declare(strict_types=1);

namespace QuerySentinel\Contracts;

use QuerySentinel\Core\ProfileReport;

/**
 * Profiler contract for capturing and analyzing multiple queries.
 *
 * Implementations capture all database queries executed during a
 * callback, analyze each individually, and aggregate the results
 * into a comprehensive profile report.
 */
interface ProfilerInterface
{
    /**
     * Profile a closure, capturing and analyzing all queries it executes.
     */
    public function profile(\Closure $callback): ProfileReport;
}
