<?php

declare(strict_types=1);

namespace QuerySentinel\Rules;

final class NoIndexRule extends BaseRule
{
    public function evaluate(array $metrics): ?array
    {
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
