<?php

declare(strict_types=1);

namespace QuerySentinel\Rules;

final class FullTableScanRule extends BaseRule
{
    public function evaluate(array $metrics): ?array
    {
        if (! ($metrics['has_table_scan'] ?? false)) {
            return null;
        }

        $rowsExamined = $metrics['rows_examined'] ?? 0;

        return $this->finding(
            severity: $rowsExamined > 10_000 ? 'critical' : 'warning',
            title: 'Full table scan detected',
            description: sprintf(
                'Query performs a full table scan examining %s rows. This bypasses indexes entirely and reads every row in the table.',
                number_format($rowsExamined),
            ),
            recommendation: 'Add a composite index on the filtered/joined columns, or verify the existing index covers the WHERE clause in leftmost-prefix order.',
            metadata: ['rows_examined' => $rowsExamined],
        );
    }

    public function key(): string
    {
        return 'full_table_scan';
    }

    public function name(): string
    {
        return 'Full Table Scan Detection';
    }
}
