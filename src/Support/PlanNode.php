<?php

declare(strict_types=1);

namespace QuerySentinel\Support;

final class PlanNode
{
    /** @var PlanNode[] */
    public array $children = [];

    public function __construct(
        public readonly string $operation,
        public readonly string $rawLine,
        public readonly int $depth = 0,
        public readonly ?float $actualTimeStart = null,
        public readonly ?float $actualTimeEnd = null,
        public readonly ?int $actualRows = null,
        public readonly ?int $loops = null,
        public readonly ?float $estimatedCost = null,
        public readonly ?float $estimatedRows = null,
        public readonly ?string $table = null,
        public readonly ?string $index = null,
        public readonly ?string $accessType = null,
        public readonly bool $neverExecuted = false,
    ) {}

    /**
     * Whether this node represents an I/O operation (reads from storage/index).
     */
    public function isIoOperation(): bool
    {
        if ($this->accessType === null) {
            return false;
        }

        return in_array($this->accessType, [
            'table_scan',
            'index_lookup',
            'index_range_scan',
            'covering_index_lookup',
            'single_row_lookup',
            'index_scan',
            'fulltext_index',
        ], true);
    }

    /**
     * Total rows processed by this node: actual_rows * loops.
     */
    public function rowsProcessed(): int
    {
        if ($this->actualRows === null || $this->loops === null) {
            return 0;
        }

        return $this->actualRows * $this->loops;
    }

    /**
     * Flatten this node and all descendants into a list.
     *
     * @return PlanNode[]
     */
    public function flatten(): array
    {
        $nodes = [$this];

        foreach ($this->children as $child) {
            $nodes = array_merge($nodes, $child->flatten());
        }

        return $nodes;
    }
}
