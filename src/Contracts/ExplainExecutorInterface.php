<?php

declare(strict_types=1);

namespace QuerySentinel\Contracts;

use QuerySentinel\Support\ExplainResult;

/**
 * Abstraction for running EXPLAIN (and EXPLAIN ANALYZE).
 * Returns ExplainResult; no exceptions. Caller decides whether to abort.
 */
interface ExplainExecutorInterface
{
    public function execute(string $sql): ExplainResult;
}
