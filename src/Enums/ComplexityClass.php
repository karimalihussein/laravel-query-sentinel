<?php

declare(strict_types=1);

namespace QuerySentinel\Enums;

/**
 * Query complexity classification derived from MySQL access types.
 *
 * Mapping from MySQL EXPLAIN access types:
 *   const / eq_ref   → Constant    O(1)
 *   ref              → Logarithmic O(log n)
 *   range            → LogRange    O(log n + k)
 *   index            → Linear      O(n)
 *   ALL              → Linear      O(n)
 *   filesort / group → Linearithmic O(n log n)
 *   nested full scan → Quadratic   O(n²)
 */
enum ComplexityClass: string
{
    case Constant = 'O(1)';
    case Logarithmic = 'O(log n)';
    case LogRange = 'O(log n + k)';
    case Linear = 'O(n)';
    case Linearithmic = 'O(n log n)';
    case Quadratic = 'O(n²)';

    public function label(): string
    {
        return match ($this) {
            self::Constant => 'Constant lookup (unique key or const table)',
            self::Logarithmic => 'Index lookup (logarithmic)',
            self::LogRange => 'Index range scan (logarithmic + range)',
            self::Linear => 'Full scan (linear)',
            self::Linearithmic => 'Sort or group by without index',
            self::Quadratic => 'Nested loop explosion (quadratic)',
        };
    }

    public function riskLevel(): string
    {
        return match ($this) {
            self::Constant, self::Logarithmic, self::LogRange => 'LOW',
            self::Linear => 'MEDIUM',
            self::Linearithmic => 'MEDIUM',
            self::Quadratic => 'HIGH',
        };
    }

    /**
     * Severity order for complexity comparison. Higher = worse.
     */
    public function ordinal(): int
    {
        return match ($this) {
            self::Constant => 0,
            self::Logarithmic => 1,
            self::LogRange => 2,
            self::Linear => 3,
            self::Linearithmic => 4,
            self::Quadratic => 5,
        };
    }
}
