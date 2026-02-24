<?php

declare(strict_types=1);

namespace QuerySentinel\Parsers;

use QuerySentinel\Analyzers\MetricsExtractor;
use QuerySentinel\Contracts\PlanParserInterface;
use QuerySentinel\Support\PlanNode;

/**
 * Parses MySQL EXPLAIN ANALYZE tree-format output into structured metrics.
 *
 * Handles:
 * - Tree indentation parsing (-> prefixed lines)
 * - Cost/row estimates: (cost=X rows=Y)
 * - Actual execution: (actual time=X..Y rows=Z loops=W)
 * - Never-executed branches
 * - Operation type classification
 * - Table and index identification
 *
 * Delegates metric computation to MetricsExtractor.
 */
final class ExplainPlanParser implements PlanParserInterface
{
    public function __construct(
        private readonly MetricsExtractor $extractor = new MetricsExtractor,
    ) {}

    /**
     * Parse raw EXPLAIN ANALYZE output into structured metrics.
     *
     * @return array<string, mixed>
     */
    public function parse(string $rawExplainOutput): array
    {
        if (trim($rawExplainOutput) === '') {
            return $this->extractor->extract(null, '');
        }

        $lines = $this->splitIntoNodeLines($rawExplainOutput);
        $root = $this->buildTree($lines);

        return $this->extractor->extract($root, $rawExplainOutput);
    }

    /**
     * Build a PlanNode tree from the plan, exposed for direct tree access.
     */
    public function buildPlanTree(string $rawExplainOutput): ?PlanNode
    {
        if (trim($rawExplainOutput) === '') {
            return null;
        }

        $lines = $this->splitIntoNodeLines($rawExplainOutput);

        return $this->buildTree($lines);
    }

    /**
     * Split plan text into logical node lines.
     *
     * EXPLAIN ANALYZE may wrap long node descriptions across multiple lines.
     * Each new node starts with whitespace followed by "->".
     *
     * @return array<int, string>
     */
    private function splitIntoNodeLines(string $plan): array
    {
        $rawLines = explode("\n", $plan);
        $nodeLines = [];
        $currentLine = '';

        foreach ($rawLines as $line) {
            if ($line === '') {
                continue;
            }

            // New node: starts with optional whitespace then "->"
            if (preg_match('/^\s*->/', $line)) {
                if ($currentLine !== '') {
                    $nodeLines[] = $currentLine;
                }
                $currentLine = $line;
            } else {
                // Continuation of previous line
                $currentLine .= ' '.trim($line);
            }
        }

        if ($currentLine !== '') {
            $nodeLines[] = $currentLine;
        }

        return $nodeLines;
    }

    /**
     * Build a tree of PlanNodes from indentation-structured lines.
     *
     * Uses a stack to track parent-child relationships based on
     * indentation depth. Deeper indentation = child of previous node.
     *
     * @param  array<int, string>  $lines
     */
    private function buildTree(array $lines): ?PlanNode
    {
        $entries = [];

        foreach ($lines as $line) {
            if (! preg_match('/^(\s*)->\s*(.+)$/s', $line, $m)) {
                continue;
            }

            $indent = strlen($m[1]);
            $content = trim($m[2]);
            $node = $this->parseLine($content, $line, $indent);
            $entries[] = ['indent' => $indent, 'node' => $node];
        }

        if (empty($entries)) {
            return null;
        }

        // Build tree using stack-based indentation tracking
        $stack = [];
        $root = null;

        foreach ($entries as $entry) {
            $indent = $entry['indent'];
            $node = $entry['node'];

            // Pop back to find the parent (closest ancestor with lesser indent)
            while (! empty($stack) && $stack[count($stack) - 1]['indent'] >= $indent) {
                array_pop($stack);
            }

            if (empty($stack)) {
                $root = $node;
            } else {
                $stack[count($stack) - 1]['node']->children[] = $node;
            }

            $stack[] = ['indent' => $indent, 'node' => $node];
        }

        return $root;
    }

    /**
     * Parse a single plan line into a PlanNode.
     *
     * Extracts: operation name, estimated cost/rows, actual time/rows/loops,
     * table name, index name, access type, and never-executed flag.
     */
    private function parseLine(string $content, string $rawLine, int $depth): PlanNode
    {
        // Operation: everything before the first parenthesis group
        $operation = $this->extractOperation($content);

        // Estimated cost and rows: (cost=X rows=Y)
        // rows may use scientific notation (e.g. 1e+6)
        $estimatedCost = null;
        $estimatedRows = null;
        if (preg_match('/\(cost=([\d.,]+)\s+rows=([\d.eE+]+)\)/', $content, $m)) {
            $estimatedCost = (float) str_replace(',', '', $m[1]);
            $estimatedRows = (float) $m[2];
        }

        // Actual execution: (actual time=X..Y rows=Z loops=W)
        // rows/loops may use scientific notation (e.g. 1e+6, 1.5e+3)
        $actualTimeStart = null;
        $actualTimeEnd = null;
        $actualRows = null;
        $loops = null;
        $neverExecuted = str_contains($content, 'never executed');

        if (! $neverExecuted && preg_match(
            '/\(actual time=([\d.]+)\.\.([\d.]+)\s+rows=([\d.eE+]+)\s+loops=([\d.eE+]+)\)/',
            $content,
            $m
        )) {
            $actualTimeStart = (float) $m[1];
            $actualTimeEnd = (float) $m[2];
            $actualRows = (int) round((float) $m[3]);
            $loops = (int) round((float) $m[4]);
        }

        // Table name: "on TABLE_NAME" pattern
        $table = $this->extractTable($content);

        // Index name: "using INDEX_NAME" pattern
        $index = $this->extractIndex($content);

        // Access type classification
        $accessType = $this->classifyAccessType($operation);

        return new PlanNode(
            operation: $operation,
            rawLine: $rawLine,
            depth: $depth,
            actualTimeStart: $actualTimeStart,
            actualTimeEnd: $actualTimeEnd,
            actualRows: $actualRows,
            loops: $loops,
            estimatedCost: $estimatedCost,
            estimatedRows: $estimatedRows,
            table: $table,
            index: $index,
            accessType: $accessType,
            neverExecuted: $neverExecuted,
        );
    }

    /**
     * Extract the operation name from a plan line content.
     *
     * Strips parenthetical cost/time data, keeping just the operation description.
     */
    private function extractOperation(string $content): string
    {
        // Remove everything from the first opening parenthesis that contains cost/actual/never
        $operation = preg_replace('/\s*\((cost=|actual |never executed).*$/s', '', $content);

        return trim($operation ?? $content);
    }

    /**
     * Extract table name from "scan on TABLE", "lookup on TABLE",
     * "Constant row from TABLE", etc.
     */
    private function extractTable(string $content): ?string
    {
        if (preg_match('/(?:scan|lookup|search)\s+on\s+(\w+)/i', $content, $m)) {
            return $m[1];
        }

        // "Constant row from TABLE"
        if (preg_match('/Constant row from\s+(\w+)/i', $content, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Extract index name from "using INDEX_NAME" pattern.
     */
    private function extractIndex(string $content): ?string
    {
        if (preg_match('/using\s+(\w+)/i', $content, $m)) {
            $name = $m[1];

            // Filter out SQL keywords that could be false matches
            if (in_array(strtolower($name), ['index', 'temporary', 'where'], true)) {
                return null;
            }

            return $name;
        }

        return null;
    }

    /**
     * Classify the access type from the operation description.
     *
     * Returns a normalized access type string for metric computation.
     */
    /**
     * Classify the access type from the operation description.
     *
     * Returns a normalized access type string for metric computation.
     * Order matters: more specific patterns must be checked before general ones.
     *
     * Access type mapping to MySQL EXPLAIN types:
     *   zero_row_const           → const (O(1))
     *   const_row                → const (O(1))
     *   single_row_lookup        → eq_ref (O(1))
     *   covering_index_lookup    → ref + covering (O(log n))
     *   index_lookup             → ref (O(log n))
     *   index_range_scan         → range (O(log n + k))
     *   index_scan               → index (O(n))
     *   table_scan               → ALL (O(n))
     */
    private function classifyAccessType(string $operation): ?string
    {
        $lower = strtolower($operation);

        // Const access: "Zero rows (no matching row in const table)"
        if (str_starts_with($lower, 'zero rows')) {
            return 'zero_row_const';
        }

        // Const access: "Constant row from TABLE"
        if (str_starts_with($lower, 'constant row')) {
            return 'const_row';
        }

        // Const access: "Rows fetched before execution"
        if (str_starts_with($lower, 'rows fetched before execution')) {
            return 'const_row';
        }

        // Single-row lookups (eq_ref/const) — check BEFORE general lookups
        if (str_starts_with($lower, 'single-row covering index lookup')) {
            return 'single_row_lookup';
        }
        if (str_starts_with($lower, 'single-row index lookup')) {
            return 'single_row_lookup';
        }

        // Table scan — ALL
        if (str_starts_with($lower, 'table scan')) {
            return 'table_scan';
        }

        // Index range scan — range
        if (str_starts_with($lower, 'index range scan')) {
            return 'index_range_scan';
        }

        // Covering index lookup — ref with covering
        if (str_starts_with($lower, 'covering index lookup')) {
            return 'covering_index_lookup';
        }

        // Index lookup — ref
        if (str_starts_with($lower, 'index lookup')) {
            return 'index_lookup';
        }

        // Index scan (full) — index
        if (str_starts_with($lower, 'index scan')) {
            return 'index_scan';
        }

        // Full-text index
        if (str_starts_with($lower, 'full-text index')) {
            return 'fulltext_index';
        }

        // Control-flow / join nodes (not I/O access types)
        if (str_contains($lower, 'nested loop')) {
            return 'nested_loop';
        }
        if (str_starts_with($lower, 'sort')) {
            return 'sort';
        }
        if (str_starts_with($lower, 'filter')) {
            return 'filter';
        }
        if (str_starts_with($lower, 'limit')) {
            return 'limit';
        }
        if (str_starts_with($lower, 'materialize')) {
            return 'materialize';
        }
        if (str_starts_with($lower, 'stream results')) {
            return 'stream';
        }
        if (str_starts_with($lower, 'group')) {
            return 'group';
        }
        if (str_starts_with($lower, 'hash join') || str_starts_with($lower, 'hash')) {
            return 'hash_join';
        }

        return null;
    }
}
