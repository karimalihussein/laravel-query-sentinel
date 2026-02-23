<?php

declare(strict_types=1);

namespace QuerySentinel\Contracts;

interface PlanParserInterface
{
    /**
     * Parse raw EXPLAIN output into structured metrics.
     *
     * @return array<string, mixed>
     */
    public function parse(string $rawExplainOutput): array;
}
