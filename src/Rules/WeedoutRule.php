<?php

declare(strict_types=1);

namespace QuerySentinel\Rules;

final class WeedoutRule extends BaseRule
{
    public function evaluate(array $metrics): ?array
    {
        if (! ($metrics['has_weedout'] ?? false)) {
            return null;
        }

        return $this->finding(
            severity: 'warning',
            title: 'Weedout (semi-join materialization) detected',
            description: 'MySQL is using a temporary table to deduplicate rows from a semi-join. This indicates the optimizer chose a materialization strategy rather than an efficient first-match or EXISTS pattern.',
            recommendation: 'Convert correlated subqueries to EXISTS semi-joins. Consider a denormalized lookup table (e.g., client_user_access) for frequently filtered dimensions.',
        );
    }

    public function key(): string
    {
        return 'weedout';
    }

    public function name(): string
    {
        return 'Weedout Detection';
    }
}
