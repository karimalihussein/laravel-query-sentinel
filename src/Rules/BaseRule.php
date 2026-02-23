<?php

declare(strict_types=1);

namespace QuerySentinel\Rules;

use QuerySentinel\Contracts\RuleInterface;

abstract class BaseRule implements RuleInterface
{
    abstract public function evaluate(array $metrics): ?array;

    abstract public function key(): string;

    abstract public function name(): string;

    /**
     * Build a standardized finding array.
     *
     * @param  array<string, mixed>  $metadata
     * @return array{severity: string, category: string, title: string, description: string, recommendation: string|null, metadata: array<string, mixed>}
     */
    protected function finding(
        string $severity,
        string $title,
        string $description,
        ?string $recommendation = null,
        array $metadata = [],
    ): array {
        return [
            'severity' => $severity,
            'category' => $this->key(),
            'title' => $title,
            'description' => $description,
            'recommendation' => $recommendation,
            'metadata' => $metadata,
        ];
    }
}
