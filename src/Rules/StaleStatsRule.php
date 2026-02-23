<?php

declare(strict_types=1);

namespace QuerySentinel\Rules;

final class StaleStatsRule extends BaseRule
{
    public function evaluate(array $metrics): ?array
    {
        $perTable = $metrics['per_table_estimates'] ?? [];

        $staleTables = [];

        foreach ($perTable as $table => $data) {
            $estimated = (int) ($data['estimated_rows'] ?? 0);
            $actual = $data['actual_rows'] ?? 0;
            $loops = $data['loops'] ?? 1;

            if ($estimated <= 0 || $actual <= 0) {
                continue;
            }

            $deviation = max($estimated, $actual) / max(min($estimated, $actual), 1);

            if ($deviation > 10) {
                $staleTables[$table] = [
                    'estimated' => $estimated,
                    'actual' => $actual * $loops,
                    'deviation' => round($deviation, 1),
                ];
            }
        }

        if (empty($staleTables)) {
            return null;
        }

        $tableList = implode(', ', array_map(
            fn ($t, $d) => sprintf('`%s` (%sx deviation)', $t, $d['deviation']),
            array_keys($staleTables),
            $staleTables,
        ));

        return $this->finding(
            severity: 'warning',
            title: 'Stale statistics detected',
            description: sprintf(
                'EXPLAIN row estimates differ >10x from actual rows for: %s. The optimizer may choose a suboptimal plan.',
                $tableList,
            ),
            recommendation: implode('; ', array_map(
                fn ($t) => sprintf('ANALYZE TABLE `%s`', $t),
                array_keys($staleTables),
            )),
            metadata: ['stale_tables' => $staleTables],
        );
    }

    public function key(): string
    {
        return 'stale_stats';
    }

    public function name(): string
    {
        return 'Stale Statistics Detection';
    }
}
