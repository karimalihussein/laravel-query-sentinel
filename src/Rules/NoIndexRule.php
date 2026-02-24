<?php

declare(strict_types=1);

namespace QuerySentinel\Rules;

final class NoIndexRule extends BaseRule
{
    public function evaluate(array $metrics): ?array
    {
        // Zero-row const: index WAS used (const table optimization)
        if ($metrics['is_zero_row_const'] ?? false) {
            return null;
        }

        // Intentional full scan: no predicates to index
        if ($metrics['is_intentional_scan'] ?? false) {
            return null;
        }

        // Const/eq_ref access: index used
        $accessType = $metrics['primary_access_type'] ?? null;
        if (in_array($accessType, ['zero_row_const', 'const_row', 'single_row_lookup'], true)) {
            return null;
        }

        $isIndexBacked = $metrics['is_index_backed'] ?? false;

        if ($isIndexBacked) {
            return null;
        }

        $indexesUsed = $metrics['indexes_used'] ?? [];

        if (! empty($indexesUsed)) {
            return null;
        }

        return $this->finding(
            severity: 'critical',
            title: 'No index used',
            description: 'Query does not use any index. Every row in the table must be read and evaluated, resulting in O(n) complexity that scales linearly with table size.',
            recommendation: 'Create a composite index covering the WHERE clause columns in selectivity order (most selective first). If joining, ensure foreign key columns are indexed.',
            metadata: ['tables_accessed' => $metrics['tables_accessed'] ?? []],
        );
    }

    public function key(): string
    {
        return 'no_index';
    }

    public function name(): string
    {
        return 'Missing Index Detection';
    }
}
