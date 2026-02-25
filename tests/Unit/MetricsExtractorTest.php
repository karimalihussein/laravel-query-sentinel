<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QuerySentinel\Analyzers\MetricsExtractor;
use QuerySentinel\Support\PlanNode;

final class MetricsExtractorTest extends TestCase
{
    private MetricsExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new MetricsExtractor;
    }

    // ---------------------------------------------------------------
    // Null/empty plan
    // ---------------------------------------------------------------

    public function test_null_root_returns_empty_metrics(): void
    {
        $metrics = $this->extractor->extract(null, '');

        $this->assertSame(0.0, $metrics['execution_time_ms']);
        $this->assertSame(0, $metrics['rows_examined']);
        $this->assertSame(0, $metrics['rows_returned']);
        $this->assertNull($metrics['primary_access_type']);
        $this->assertSame('unknown', $metrics['mysql_access_type']);
        $this->assertFalse($metrics['is_zero_row_const']);
        $this->assertFalse($metrics['is_index_backed']);
        $this->assertSame('O(n)', $metrics['complexity']); // default
    }

    // ---------------------------------------------------------------
    // Access type severity: worst wins
    // ---------------------------------------------------------------

    public function test_worst_access_type_wins_in_join(): void
    {
        // Inner node: index_lookup (severity 4), outer: table_scan (severity 7)
        $innerNode = new PlanNode(
            operation: 'Index lookup on orders using idx_user',
            rawLine: '-> Index lookup on orders using idx_user (actual time=0.01..0.02 rows=50 loops=1)',
            accessType: 'index_lookup',
            table: 'orders',
            index: 'idx_user',
            actualRows: 50,
            loops: 1,
        );

        $outerNode = new PlanNode(
            operation: 'Table scan on users',
            rawLine: '-> Table scan on users (actual time=0.1..10.0 rows=50000 loops=1)',
            accessType: 'table_scan',
            table: 'users',
            actualRows: 50000,
            loops: 1,
        );

        $root = new PlanNode(
            operation: 'Nested loop inner join',
            rawLine: '-> Nested loop inner join (actual time=0.1..10.0 rows=50000 loops=1)',
            accessType: 'nested_loop',
            actualTimeEnd: 10.0,
            actualRows: 50000,
            loops: 1,
        );
        $root->children = [$outerNode, $innerNode];

        $metrics = $this->extractor->extract($root, '');

        // table_scan is worse than index_lookup → primary access type is table_scan
        $this->assertSame('table_scan', $metrics['primary_access_type']);
        $this->assertSame('ALL', $metrics['mysql_access_type']);
    }

    public function test_index_lookup_is_primary_when_no_table_scan(): void
    {
        $node = new PlanNode(
            operation: 'Index lookup on users using idx_email',
            rawLine: '-> Index lookup (actual time=0.01..0.01 rows=1 loops=1)',
            accessType: 'index_lookup',
            table: 'users',
            index: 'idx_email',
            actualRows: 1,
            loops: 1,
        );

        $root = new PlanNode(
            operation: 'Limit: 50 row(s)',
            rawLine: '-> Limit: 50 row(s) (actual time=0.01..0.01 rows=1 loops=1)',
            accessType: 'limit',
            actualTimeEnd: 0.01,
            actualRows: 1,
            loops: 1,
        );
        $root->children = [$node];

        $metrics = $this->extractor->extract($root, '');

        $this->assertSame('index_lookup', $metrics['primary_access_type']);
        $this->assertSame('ref', $metrics['mysql_access_type']);
        $this->assertTrue($metrics['is_index_backed']);
    }

    // ---------------------------------------------------------------
    // Zero-row const detection
    // ---------------------------------------------------------------

    public function test_zero_row_const_detected(): void
    {
        $root = new PlanNode(
            operation: 'Zero rows (no matching row in const table)',
            rawLine: '-> Zero rows (cost=0..0 rows=0) (actual time=0.003..0.003 rows=0 loops=1)',
            accessType: 'zero_row_const',
            actualTimeEnd: 0.003,
            actualRows: 0,
            loops: 1,
        );

        $metrics = $this->extractor->extract($root, '');

        $this->assertTrue($metrics['is_zero_row_const']);
        $this->assertSame('O(1)', $metrics['complexity']);
        $this->assertTrue($metrics['is_index_backed']);
    }

    public function test_non_zero_row_const_not_detected(): void
    {
        $root = new PlanNode(
            operation: 'Index lookup on users using idx_email',
            rawLine: '-> Index lookup (actual time=0.01..0.01 rows=1 loops=1)',
            accessType: 'index_lookup',
            table: 'users',
            actualRows: 1,
            loops: 1,
            actualTimeEnd: 0.01,
        );

        $metrics = $this->extractor->extract($root, '');

        $this->assertFalse($metrics['is_zero_row_const']);
    }

    // ---------------------------------------------------------------
    // Index-backed detection
    // ---------------------------------------------------------------

    public function test_all_index_access_types_are_index_backed(): void
    {
        $indexTypes = [
            'index_lookup',
            'index_range_scan',
            'covering_index_lookup',
            'single_row_lookup',
            'index_scan',
            'fulltext_index',
            'zero_row_const',
            'const_row',
        ];

        foreach ($indexTypes as $accessType) {
            $root = new PlanNode(
                operation: "test $accessType",
                rawLine: '-> test (actual time=0.01..0.01 rows=1 loops=1)',
                accessType: $accessType,
                actualTimeEnd: 0.01,
                actualRows: $accessType === 'zero_row_const' ? 0 : 1,
                loops: 1,
            );

            $metrics = $this->extractor->extract($root, '');
            $this->assertTrue(
                $metrics['is_index_backed'],
                "Access type '$accessType' should be index backed"
            );
        }
    }

    public function test_table_scan_is_not_index_backed(): void
    {
        $root = new PlanNode(
            operation: 'Table scan on users',
            rawLine: '-> Table scan on users (actual time=0.1..10.0 rows=50000 loops=1)',
            accessType: 'table_scan',
            table: 'users',
            actualTimeEnd: 10.0,
            actualRows: 50000,
            loops: 1,
        );

        $metrics = $this->extractor->extract($root, '');
        $this->assertFalse($metrics['is_index_backed']);
    }

    // ---------------------------------------------------------------
    // Complexity classification
    // ---------------------------------------------------------------

    public function test_complexity_constant_for_const_access_types(): void
    {
        foreach (['zero_row_const', 'const_row', 'single_row_lookup'] as $type) {
            $root = new PlanNode(
                operation: "test $type",
                rawLine: '-> test (actual time=0.01..0.01 rows=1 loops=1)',
                accessType: $type,
                actualTimeEnd: 0.01,
                actualRows: $type === 'zero_row_const' ? 0 : 1,
                loops: 1,
            );

            $metrics = $this->extractor->extract($root, '');
            $this->assertSame('O(1)', $metrics['complexity'], "Access type '$type' should be O(1)");
        }
    }

    public function test_complexity_logarithmic_for_index_lookups(): void
    {
        foreach (['covering_index_lookup', 'index_lookup', 'fulltext_index'] as $type) {
            $root = new PlanNode(
                operation: "test $type",
                rawLine: '-> test (actual time=0.01..0.1 rows=10 loops=1)',
                accessType: $type,
                actualTimeEnd: 0.1,
                actualRows: 10,
                loops: 1,
            );

            $metrics = $this->extractor->extract($root, '');
            $this->assertSame('O(log n)', $metrics['complexity'], "Access type '$type' should be O(log n)");
        }
    }

    public function test_complexity_log_range_for_index_range_scan(): void
    {
        $root = new PlanNode(
            operation: 'Index range scan on users using idx_age',
            rawLine: '-> Index range scan (actual time=0.05..0.5 rows=500 loops=1)',
            accessType: 'index_range_scan',
            actualTimeEnd: 0.5,
            actualRows: 500,
            loops: 1,
        );

        $metrics = $this->extractor->extract($root, '');
        $this->assertSame('O(log n + k)', $metrics['complexity']);
    }

    public function test_complexity_linear_for_table_scan(): void
    {
        $root = new PlanNode(
            operation: 'Table scan on users',
            rawLine: '-> Table scan on users (actual time=0.1..10.0 rows=50000 loops=1)',
            accessType: 'table_scan',
            table: 'users',
            actualTimeEnd: 10.0,
            actualRows: 50000,
            loops: 1,
        );

        $metrics = $this->extractor->extract($root, '');
        $this->assertSame('O(n)', $metrics['complexity']);
    }

    public function test_complexity_linear_for_index_scan(): void
    {
        $root = new PlanNode(
            operation: 'Index scan on users using idx_name',
            rawLine: '-> Index scan (actual time=0.1..10.0 rows=50000 loops=1)',
            accessType: 'index_scan',
            actualTimeEnd: 10.0,
            actualRows: 50000,
            loops: 1,
        );

        $metrics = $this->extractor->extract($root, '');
        $this->assertSame('O(n)', $metrics['complexity']);
    }

    // ---------------------------------------------------------------
    // Complexity modifiers
    // ---------------------------------------------------------------

    public function test_filesort_raises_to_linearithmic(): void
    {
        $node = new PlanNode(
            operation: 'Index lookup on users using idx_status',
            rawLine: '-> Index lookup (actual time=0.01..0.5 rows=100 loops=1)',
            accessType: 'index_lookup',
            actualRows: 100,
            loops: 1,
        );

        $root = new PlanNode(
            operation: 'Sort: users.name ASC',
            rawLine: '-> Sort: users.name ASC (actual time=1.0..1.1 rows=100 loops=1)',
            actualTimeEnd: 1.1,
            actualRows: 100,
            loops: 1,
        );
        $root->children = [$node];

        // Include "Sort:" in rawPlan for filesort detection
        $metrics = $this->extractor->extract($root, "-> Sort: users.name ASC\n-> Index lookup");

        $this->assertSame('O(n log n)', $metrics['complexity']);
    }

    public function test_table_scan_with_nested_loop_is_quadratic(): void
    {
        $scanNode = new PlanNode(
            operation: 'Table scan on users',
            rawLine: '-> Table scan on users (actual time=0.1..10.0 rows=50000 loops=1)',
            accessType: 'table_scan',
            table: 'users',
            actualRows: 50000,
            loops: 1,
        );

        $lookupNode = new PlanNode(
            operation: 'Index lookup on orders using idx_user',
            rawLine: '-> Index lookup (actual time=0.01..0.02 rows=5 loops=50000)',
            accessType: 'index_lookup',
            table: 'orders',
            actualRows: 5,
            loops: 50000,
        );

        $root = new PlanNode(
            operation: 'Nested loop inner join',
            rawLine: '-> Nested loop inner join (actual time=0.1..500.0 rows=250000 loops=1)',
            accessType: 'nested_loop',
            actualTimeEnd: 500.0,
            actualRows: 250000,
            loops: 1,
        );
        $root->children = [$scanNode, $lookupNode];

        $metrics = $this->extractor->extract($root, '');

        $this->assertSame('O(n²)', $metrics['complexity']);
    }

    // ---------------------------------------------------------------
    // Table scan exclusion for derived tables
    // ---------------------------------------------------------------

    public function test_derived_table_scan_excluded(): void
    {
        $drvNode = new PlanNode(
            operation: 'Table scan on drv',
            rawLine: '-> Table scan on drv (actual time=0.01..0.02 rows=10 loops=1)',
            accessType: 'table_scan',
            table: 'drv',
            actualRows: 10,
            loops: 1,
        );

        $root = new PlanNode(
            operation: 'Limit: 50 row(s)',
            rawLine: '-> Limit: 50 row(s) (actual time=0.01..0.02 rows=10 loops=1)',
            accessType: 'limit',
            actualTimeEnd: 0.02,
            actualRows: 10,
            loops: 1,
        );
        $root->children = [$drvNode];

        $metrics = $this->extractor->extract($root, '');

        $this->assertFalse($metrics['has_table_scan']);
    }

    // ---------------------------------------------------------------
    // Covering index detection
    // ---------------------------------------------------------------

    public function test_covering_index_from_access_type(): void
    {
        $root = new PlanNode(
            operation: 'Covering index lookup on users using idx_cover',
            rawLine: '-> Covering index lookup (actual time=0.01..0.05 rows=100 loops=1)',
            accessType: 'covering_index_lookup',
            table: 'users',
            index: 'idx_cover',
            actualTimeEnd: 0.05,
            actualRows: 100,
            loops: 1,
        );

        $metrics = $this->extractor->extract($root, '');

        $this->assertTrue($metrics['has_covering_index']);
    }

    public function test_covering_index_from_plan_text_fallback(): void
    {
        $root = new PlanNode(
            operation: 'Index lookup on users using idx_name',
            rawLine: '-> Index lookup (actual time=0.01..0.05 rows=100 loops=1)',
            accessType: 'index_lookup',
            table: 'users',
            index: 'idx_name',
            actualTimeEnd: 0.05,
            actualRows: 100,
            loops: 1,
        );

        // "Covering index" appears in raw plan text
        $metrics = $this->extractor->extract($root, 'Covering index lookup on users using idx_name');

        $this->assertTrue($metrics['has_covering_index']);
    }

    // ---------------------------------------------------------------
    // Rows examined calculation
    // ---------------------------------------------------------------

    public function test_rows_examined_sums_io_nodes(): void
    {
        $node1 = new PlanNode(
            operation: 'Index lookup on users',
            rawLine: '',
            accessType: 'index_lookup',
            table: 'users',
            actualRows: 50,
            loops: 1,
        );

        $node2 = new PlanNode(
            operation: 'Single-row index lookup on orders',
            rawLine: '',
            accessType: 'single_row_lookup',
            table: 'orders',
            actualRows: 1,
            loops: 50,
        );

        $root = new PlanNode(
            operation: 'Nested loop inner join',
            rawLine: '',
            accessType: 'nested_loop',
            actualTimeEnd: 1.0,
            actualRows: 50,
            loops: 1,
        );
        $root->children = [$node1, $node2];

        $metrics = $this->extractor->extract($root, '');

        // users: 50*1=50, orders: 1*50=50 → 100
        $this->assertSame(100, $metrics['rows_examined']);
    }

    public function test_zero_row_const_has_zero_rows_examined(): void
    {
        $root = new PlanNode(
            operation: 'Zero rows (no matching row in const table)',
            rawLine: '-> Zero rows (actual time=0.003..0.003 rows=0 loops=1)',
            accessType: 'zero_row_const',
            actualTimeEnd: 0.003,
            actualRows: 0,
            loops: 1,
        );

        $metrics = $this->extractor->extract($root, '');

        $this->assertSame(0, $metrics['rows_examined']);
    }

    // ---------------------------------------------------------------
    // MySQL access type mapping completeness
    // ---------------------------------------------------------------

    public function test_mysql_access_type_mapping_complete(): void
    {
        $expectedMappings = [
            'zero_row_const' => 'const',
            'const_row' => 'const',
            'single_row_lookup' => 'eq_ref',
            'covering_index_lookup' => 'ref',
            'index_lookup' => 'ref',
            'fulltext_index' => 'fulltext',
            'index_range_scan' => 'range',
            'index_scan' => 'index',
            'table_scan' => 'ALL',
        ];

        foreach ($expectedMappings as $internal => $expected) {
            $root = new PlanNode(
                operation: "test $internal",
                rawLine: '-> test (actual time=0.01..0.01 rows=1 loops=1)',
                accessType: $internal,
                actualTimeEnd: 0.01,
                actualRows: $internal === 'zero_row_const' ? 0 : 1,
                loops: 1,
            );

            $metrics = $this->extractor->extract($root, '');
            $this->assertSame(
                $expected,
                $metrics['mysql_access_type'],
                "Internal type '$internal' should map to MySQL type '$expected'"
            );
        }
    }

    // ---------------------------------------------------------------
    // Fanout factor
    // ---------------------------------------------------------------

    public function test_fanout_factor_calculated_from_io_nodes(): void
    {
        $node1 = new PlanNode(
            operation: 'Index lookup on orders',
            rawLine: '',
            accessType: 'index_lookup',
            table: 'orders',
            actualRows: 5000,
            loops: 1,
        );

        $node2 = new PlanNode(
            operation: 'Single-row index lookup on users',
            rawLine: '',
            accessType: 'single_row_lookup',
            table: 'users',
            actualRows: 1,
            loops: 5000,
        );

        $root = new PlanNode(
            operation: 'Nested loop inner join',
            rawLine: '',
            accessType: 'nested_loop',
            actualTimeEnd: 10.0,
            actualRows: 5000,
            loops: 1,
        );
        $root->children = [$node1, $node2];

        $metrics = $this->extractor->extract($root, '');

        // Max fanout: orders=5000*1=5000, users=1*5000=5000 → 5000.0
        $this->assertSame(5000.0, $metrics['fanout_factor']);
    }
}
