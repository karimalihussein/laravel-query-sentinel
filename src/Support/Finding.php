<?php

declare(strict_types=1);

namespace QuerySentinel\Support;

use QuerySentinel\Enums\Severity;

/**
 * Structured performance finding with severity, category, and recommendation.
 *
 * Atomic unit of the diagnostic reporting framework. Each finding has a
 * traffic-light severity, analysis category, human-readable title/description,
 * and optional actionable recommendation.
 *
 * @phpstan-type FindingArray array{severity: string, category: string, title: string, description: string, recommendation: ?string, metadata: array<string, mixed>}
 */
final readonly class Finding
{
    /**
     * @param  array<string, mixed>  $metadata  Extra structured data (column names, ratios, etc.)
     */
    public function __construct(
        public Severity $severity,
        public string $category,
        public string $title,
        public string $description,
        public ?string $recommendation = null,
        public array $metadata = [],
    ) {}

    /**
     * Create a Finding from an existing rule-based finding array.
     *
     * @param  array{severity?: string, category?: string, title?: string, description?: string, recommendation?: string}  $legacy
     */
    public static function fromLegacy(array $legacy): self
    {
        $severity = Severity::tryFrom($legacy['severity'] ?? 'info') ?? Severity::Info;

        return new self(
            severity: $severity,
            category: $legacy['category'] ?? 'rule',
            title: $legacy['title'] ?? 'Unknown',
            description: $legacy['description'] ?? '',
            recommendation: $legacy['recommendation'] ?? null,
        );
    }

    /**
     * @return FindingArray
     */
    public function toArray(): array
    {
        return [
            'severity' => $this->severity->value,
            'category' => $this->category,
            'title' => $this->title,
            'description' => $this->description,
            'recommendation' => $this->recommendation,
            'metadata' => $this->metadata,
        ];
    }
}
