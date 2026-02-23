<?php

declare(strict_types=1);

namespace QuerySentinel\Rules;

final class LimitIneffectiveRule extends BaseRule
{
    public function evaluate(array $metrics): ?array
    {
        $hasEarlyTermination = $metrics['has_early_termination'] ?? false;
        $rowsExamined = $metrics['rows_examined'] ?? 0;
        $rowsReturned = $metrics['rows_returned'] ?? 0;

        // If there's no LIMIT or LIMIT is effective, nothing to report
        if ($hasEarlyTermination || $rowsExamined <= 0) {
            return null;
        }

        // Only flag when the query examines significantly more rows than returned
        // and there's no early termination — LIMIT is present but not reducing work
        if ($rowsReturned > 0 && $rowsExamined > $rowsReturned * 50) {
            return $this->finding(
                severity: 'warning',
                title: 'LIMIT is not reducing work',
                description: sprintf(
                    'Query returns %s rows but examines %s rows (%.0fx). LIMIT is present but MySQL must scan all matching rows before applying it, likely due to filesort or non-index-backed ORDER BY.',
                    number_format($rowsReturned),
                    number_format($rowsExamined),
                    $rowsExamined / max($rowsReturned, 1),
                ),
                recommendation: 'Ensure ORDER BY columns are covered by the driving index to enable LIMIT early termination. Add sort columns to the composite index tail.',
                metadata: [
                    'rows_examined' => $rowsExamined,
                    'rows_returned' => $rowsReturned,
                    'ratio' => round($rowsExamined / max($rowsReturned, 1), 1),
                ],
            );
        }

        return null;
    }

    public function key(): string
    {
        return 'limit_ineffective';
    }

    public function name(): string
    {
        return 'LIMIT Effectiveness Check';
    }
}
