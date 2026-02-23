<?php

declare(strict_types=1);

namespace QuerySentinel\Rules;

final class DeepNestedLoopRule extends BaseRule
{
    private int $threshold;

    public function __construct(int $threshold = 4)
    {
        $this->threshold = $threshold;
    }

    public function evaluate(array $metrics): ?array
    {
        $depth = $metrics['nested_loop_depth'] ?? 0;

        if ($depth < $this->threshold) {
            return null;
        }

        return $this->finding(
            severity: $depth >= 6 ? 'critical' : 'warning',
            title: sprintf('Deep nested loop nesting: %d joins', $depth),
            description: sprintf(
                'Query uses %d nested loop joins. Each additional join level amplifies row processing. Above %d joins, performance degrades rapidly as the inner tables are probed for every outer row combination.',
                $depth,
                $this->threshold,
            ),
            recommendation: 'Reduce join depth by pre-filtering with derived subqueries. Consider denormalized tables for frequently joined dimensions.',
            metadata: ['depth' => $depth, 'threshold' => $this->threshold],
        );
    }

    public function key(): string
    {
        return 'deep_nested_loop';
    }

    public function name(): string
    {
        return 'Deep Nested Loop Detection';
    }
}
