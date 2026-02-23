<?php

declare(strict_types=1);

namespace QuerySentinel\Rules;

use QuerySentinel\Enums\ComplexityClass;

final class QuadraticComplexityRule extends BaseRule
{
    public function evaluate(array $metrics): ?array
    {
        $complexityValue = $metrics['complexity'] ?? null;
        $complexity = $complexityValue !== null
            ? ComplexityClass::tryFrom($complexityValue)
            : null;

        if ($complexity !== ComplexityClass::Quadratic) {
            return null;
        }

        $maxLoops = $metrics['max_loops'] ?? 0;
        $nestedDepth = $metrics['nested_loop_depth'] ?? 0;

        return $this->finding(
            severity: 'critical',
            title: 'Quadratic complexity detected: O(n²)',
            description: sprintf(
                'Query exhibits quadratic growth characteristics (nested depth=%d, max loops=%s). '
                .'Doubling the data size will quadruple execution time. This will not scale.',
                $nestedDepth,
                number_format($maxLoops),
            ),
            recommendation: 'Restructure with derived subqueries to reduce the driving set before joining. Pre-materialize selective filters into the innermost subquery.',
            metadata: [
                'complexity' => $complexity->value,
                'max_loops' => $maxLoops,
                'nested_depth' => $nestedDepth,
            ],
        );
    }

    public function key(): string
    {
        return 'quadratic_complexity';
    }

    public function name(): string
    {
        return 'Quadratic Complexity Detection';
    }
}
