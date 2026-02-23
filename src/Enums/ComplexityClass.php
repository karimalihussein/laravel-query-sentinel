<?php

declare(strict_types=1);

namespace QuerySentinel\Enums;

enum ComplexityClass: string
{
    case Limit = 'O(limit)';
    case Range = 'O(range)';
    case Linear = 'O(n)';
    case Linearithmic = 'O(n log n)';
    case Quadratic = 'O(n²)';

    public function label(): string
    {
        return match ($this) {
            self::Limit => 'LIMIT-optimized (early termination)',
            self::Range => 'Range scan on index',
            self::Linear => 'Linear full scan',
            self::Linearithmic => 'Sort on full result set',
            self::Quadratic => 'Nested loop explosion',
        };
    }

    public function riskLevel(): string
    {
        return match ($this) {
            self::Limit, self::Range => 'LOW',
            self::Linear => 'MEDIUM',
            self::Linearithmic => 'MEDIUM',
            self::Quadratic => 'HIGH',
        };
    }

    public function scalabilityFactor(): float
    {
        return match ($this) {
            self::Limit => 1.0,
            self::Range => 1.2,
            self::Linear => 1.0,
            self::Linearithmic => 1.1,
            self::Quadratic => 2.0,
        };
    }
}
