<?php

declare(strict_types=1);

namespace QuerySentinel\Rules;

final class IndexMergeRule extends BaseRule
{
    public function evaluate(array $metrics): ?array
    {
        if (! ($metrics['has_index_merge'] ?? false)) {
            return null;
        }

        return $this->finding(
            severity: 'warning',
            title: 'Index merge detected',
            description: 'MySQL is merging results from multiple single-column indexes. This is slower than using a single composite index because it requires reading from multiple B-trees and intersecting/unioning the results.',
            recommendation: 'Replace the individual single-column indexes with one composite index that covers all filtered columns in selectivity order.',
            metadata: ['indexes_used' => $metrics['indexes_used'] ?? []],
        );
    }

    public function key(): string
    {
        return 'index_merge';
    }

    public function name(): string
    {
        return 'Index Merge Detection';
    }
}
