<?php

declare(strict_types=1);

namespace QuerySentinel\Support;

use QuerySentinel\Enums\ComplexityClass;

/**
 * Deep execution metrics extracted from EXPLAIN ANALYZE + SHOW INDEX.
 *
 * Captures nested loop depth, join fanouts, B-tree depth estimates,
 * logical/physical read approximations, and complexity classification.
 */
final readonly class ExecutionProfile
{
    /**
     * @param  array<string, float>  $joinFanouts  table => actual_rows * loops
     * @param  array<string, int>  $btreeDepths  index_name => estimated depth
     */
    public function __construct(
        public int $nestedLoopDepth,
        public array $joinFanouts,
        public array $btreeDepths,
        public int $logicalReads,
        public int $physicalReads,
        public ComplexityClass $scanComplexity,
        public ComplexityClass $sortComplexity,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'nested_loop_depth' => $this->nestedLoopDepth,
            'join_fanouts' => $this->joinFanouts,
            'btree_depths' => $this->btreeDepths,
            'logical_reads' => $this->logicalReads,
            'physical_reads' => $this->physicalReads,
            'scan_complexity' => $this->scanComplexity->value,
            'scan_complexity_label' => $this->scanComplexity->label(),
            'scan_risk' => $this->scanComplexity->riskLevel(),
            'sort_complexity' => $this->sortComplexity->value,
            'sort_complexity_label' => $this->sortComplexity->label(),
        ];
    }
}
