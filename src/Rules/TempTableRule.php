<?php

declare(strict_types=1);

namespace QuerySentinel\Rules;

final class TempTableRule extends BaseRule
{
    public function evaluate(array $metrics): ?array
    {
        if (! ($metrics['has_temp_table'] ?? false)) {
            return null;
        }

        $hasDiskTemp = $metrics['has_disk_temp'] ?? false;
        $severity = $hasDiskTemp ? 'critical' : 'warning';
        $diskNote = $hasDiskTemp
            ? ' Data is spilling to disk, severely impacting performance.'
            : ' Currently in-memory, but may spill to disk at scale.';

        return $this->finding(
            severity: $severity,
            title: 'Temporary table detected'.($hasDiskTemp ? ' (disk-based)' : ''),
            description: 'MySQL is using a temporary table to process intermediate results.'.$diskNote,
            recommendation: 'Restructure the query to avoid GROUP BY / DISTINCT on non-indexed columns. Ensure ORDER BY columns are covered by the driving index.',
            metadata: ['disk_based' => $hasDiskTemp],
        );
    }

    public function key(): string
    {
        return 'temp_table';
    }

    public function name(): string
    {
        return 'Temporary Table Detection';
    }
}
