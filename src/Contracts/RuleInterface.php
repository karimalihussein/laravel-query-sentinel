<?php

declare(strict_types=1);

namespace QuerySentinel\Contracts;

interface RuleInterface
{
    /**
     * Evaluate metrics against this rule.
     *
     * Returns a finding array if the rule triggers, null otherwise.
     *
     * @param  array<string, mixed>  $metrics
     * @return array{severity: string, category: string, title: string, description: string, recommendation: string|null, metadata: array<string, mixed>}|null
     */
    public function evaluate(array $metrics): ?array;

    /**
     * Get the unique identifier for this rule.
     */
    public function key(): string;

    /**
     * Get the human-readable name of this rule.
     */
    public function name(): string;
}
