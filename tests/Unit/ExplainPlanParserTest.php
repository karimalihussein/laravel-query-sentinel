<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QuerySentinel\Parsers\ExplainPlanParser;

final class ExplainPlanParserTest extends TestCase
{
    private ExplainPlanParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new ExplainPlanParser;
    }

    public function test_empty_plan_returns_empty_metrics(): void
    {
        $metrics = $this->parser->parse('');

        $this->assertSame(0.0, $metrics['execution_time_ms']);
        $this->assertSame(0, $metrics['rows_examined']);
        $this->assertSame(0, $metrics['rows_returned']);
    }

    public function test_parse_simple_limit_query(): void
    {
        $plan = <<<'PLAN'
-> Limit: 50 row(s)  (cost=100 rows=50) (actual time=0.100..0.500 rows=50 loops=1)
    -> Index lookup on users using PRIMARY (id = 1)  (cost=10 rows=1) (actual time=0.050..0.450 rows=50 loops=1)
PLAN;

        $metrics = $this->parser->parse($plan);

        $this->assertSame(0.5, $metrics['execution_time_ms']);
        $this->assertSame(50, $metrics['rows_returned']);
        $this->assertSame(50, $metrics['rows_examined']);
        $this->assertContains('PRIMARY', $metrics['indexes_used']);
        $this->assertTrue($metrics['is_index_backed']);
    }

    public function test_parse_nested_loop_plan(): void
    {
        $plan = <<<'PLAN'
-> Limit: 50 row(s)  (actual time=0.095..0.095 rows=0 loops=1)
    -> Nested loop inner join  (cost=4.91 rows=1) (actual time=0.090..0.090 rows=0 loops=1)
        -> Nested loop inner join  (cost=3.50 rows=1) (actual time=0.085..0.085 rows=0 loops=1)
            -> Table scan on drv  (cost=2.50 rows=1) (actual time=0.080..0.080 rows=0 loops=1)
            -> Single-row index lookup on clients using PRIMARY (id = drv.client_id)  (cost=0.50 rows=1) (never executed)
        -> Index lookup on cua using idx_cua_user (user_id = 1, client_id = drv.client_id)  (cost=0.50 rows=1) (never executed)
PLAN;

        $metrics = $this->parser->parse($plan);

        $this->assertSame(2, $metrics['nested_loop_depth']);
        $this->assertSame(0, $metrics['rows_returned']);
        $this->assertFalse($metrics['has_table_scan']); // drv scan excluded
    }

    public function test_detect_table_scan_on_real_table(): void
    {
        $plan = <<<'PLAN'
-> Table scan on users  (cost=100 rows=50000) (actual time=0.100..150.000 rows=50000 loops=1)
PLAN;

        $metrics = $this->parser->parse($plan);

        $this->assertTrue($metrics['has_table_scan']);
        $this->assertContains('users', $metrics['tables_accessed']);
    }

    public function test_derived_table_scan_not_flagged(): void
    {
        $plan = <<<'PLAN'
-> Limit: 50 row(s)  (actual time=0.095..0.095 rows=0 loops=1)
    -> Table scan on drv  (cost=3.12 rows=1) (actual time=0.087..0.087 rows=0 loops=1)
        -> Materialize  (cost=0.62 rows=1) (actual time=0.086..0.086 rows=0 loops=1)
            -> Index lookup on s using idx_test (status_id = 1)  (cost=0.5 rows=1) (actual time=0.035..0.035 rows=0 loops=1)
PLAN;

        $metrics = $this->parser->parse($plan);

        $this->assertFalse($metrics['has_table_scan']);
        $this->assertTrue($metrics['has_materialization']);
    }

    public function test_detect_filesort(): void
    {
        $plan = <<<'PLAN'
-> Sort: users.created_at DESC, limit input to 50 row(s) per chunk  (actual time=5.000..5.100 rows=50 loops=1)
    -> Table scan on users  (cost=100 rows=50000) (actual time=0.100..4.000 rows=50000 loops=1)
PLAN;

        $metrics = $this->parser->parse($plan);

        $this->assertTrue($metrics['has_filesort']);
        $this->assertTrue($metrics['has_table_scan']);
    }

    public function test_detect_weedout(): void
    {
        $plan = <<<'PLAN'
-> Limit: 50 row(s)  (actual time=10.000..10.100 rows=50 loops=1)
    -> Remove duplicate users using weedout  (actual time=9.000..10.000 rows=50 loops=1)
        -> Index lookup on users using idx_name (name = 'test')  (cost=10 rows=100) (actual time=0.050..8.000 rows=100 loops=1)
PLAN;

        $metrics = $this->parser->parse($plan);

        $this->assertTrue($metrics['has_weedout']);
    }

    public function test_detect_covering_index(): void
    {
        $plan = <<<'PLAN'
-> Covering index lookup on users using idx_covering (status = 1)  (cost=5 rows=100) (actual time=0.050..0.100 rows=100 loops=1)
PLAN;

        $metrics = $this->parser->parse($plan);

        $this->assertTrue($metrics['has_covering_index']);
        $this->assertTrue($metrics['is_index_backed']);
        $this->assertContains('idx_covering', $metrics['indexes_used']);
    }

    public function test_detect_early_termination(): void
    {
        $plan = <<<'PLAN'
-> Limit: 50 row(s)  (cost=100 rows=10000) (actual time=0.050..0.100 rows=50 loops=1)
    -> Index lookup on users using idx_status (status = 1)  (cost=50 rows=10000) (actual time=0.040..0.090 rows=50 loops=1)
PLAN;

        $metrics = $this->parser->parse($plan);

        $this->assertTrue($metrics['has_early_termination']);
    }

    public function test_rows_examined_sums_io_operations_only(): void
    {
        $plan = <<<'PLAN'
-> Limit: 10 row(s)  (actual time=0.100..0.500 rows=10 loops=1)
    -> Nested loop inner join  (cost=100 rows=100) (actual time=0.090..0.490 rows=10 loops=1)
        -> Index lookup on orders using idx_user (user_id = 1)  (cost=50 rows=50) (actual time=0.050..0.200 rows=50 loops=1)
        -> Single-row index lookup on users using PRIMARY (id = orders.user_id)  (cost=1 rows=1) (actual time=0.001..0.001 rows=1 loops=50)
PLAN;

        $metrics = $this->parser->parse($plan);

        // I/O ops: orders = 50 * 1 = 50, users = 1 * 50 = 50 → total 100
        $this->assertSame(100, $metrics['rows_examined']);
    }

    public function test_never_executed_branches_excluded(): void
    {
        $plan = <<<'PLAN'
-> Limit: 50 row(s)  (actual time=0.050..0.050 rows=0 loops=1)
    -> Index lookup on users using idx_email (email = 'none@test.com')  (cost=1 rows=1) (actual time=0.040..0.040 rows=0 loops=1)
    -> Single-row index lookup on profiles using PRIMARY (user_id = users.id)  (cost=1 rows=1) (never executed)
PLAN;

        $metrics = $this->parser->parse($plan);

        // users: 0 * 1 = 0, profiles: never executed = 0
        $this->assertSame(0, $metrics['rows_examined']);
    }

    public function test_complexity_classification_limit(): void
    {
        $plan = <<<'PLAN'
-> Limit: 50 row(s)  (cost=100 rows=10000) (actual time=0.050..0.100 rows=50 loops=1)
    -> Covering index lookup on users using idx_status (status = 1)  (cost=50 rows=10000) (actual time=0.040..0.090 rows=50 loops=1)
PLAN;

        $metrics = $this->parser->parse($plan);

        $this->assertSame('O(limit)', $metrics['complexity']);
    }

    public function test_complexity_classification_linear(): void
    {
        $plan = <<<'PLAN'
-> Table scan on users  (cost=100 rows=50000) (actual time=0.100..150.000 rows=50000 loops=1)
PLAN;

        $metrics = $this->parser->parse($plan);

        $this->assertSame('O(n)', $metrics['complexity']);
    }

    public function test_complexity_classification_quadratic(): void
    {
        $plan = <<<'PLAN'
-> Nested loop inner join  (cost=1000 rows=50000) (actual time=0.100..5000.000 rows=50000 loops=1)
    -> Nested loop inner join  (cost=500 rows=1000) (actual time=0.100..500.000 rows=50000 loops=1)
        -> Nested loop inner join  (cost=100 rows=500) (actual time=0.100..100.000 rows=50000 loops=1)
            -> Nested loop inner join  (cost=50 rows=100) (actual time=0.100..50.000 rows=50000 loops=1)
                -> Table scan on a  (cost=10 rows=50000) (actual time=0.100..10.000 rows=50000 loops=1)
                -> Index lookup on b using idx_b (a_id = a.id)  (cost=1 rows=10) (actual time=0.001..0.001 rows=10 loops=50000)
PLAN;

        $metrics = $this->parser->parse($plan);

        $this->assertSame('O(n²)', $metrics['complexity']);
    }

    public function test_build_plan_tree_structure(): void
    {
        $plan = <<<'PLAN'
-> Limit: 50 row(s)  (actual time=0.100..0.500 rows=50 loops=1)
    -> Sort: users.name ASC  (actual time=0.090..0.490 rows=50 loops=1)
        -> Index lookup on users using idx_status (status = 1)  (cost=50 rows=1000) (actual time=0.050..0.200 rows=100 loops=1)
PLAN;

        $root = $this->parser->buildPlanTree($plan);

        $this->assertNotNull($root);
        $this->assertStringContainsString('Limit', $root->operation);
        $this->assertCount(1, $root->children);
        $this->assertStringContainsString('Sort', $root->children[0]->operation);
        $this->assertCount(1, $root->children[0]->children);
        $this->assertStringContainsString('Index lookup', $root->children[0]->children[0]->operation);
    }

    public function test_per_table_estimates_populated(): void
    {
        $plan = <<<'PLAN'
-> Nested loop inner join  (cost=100 rows=100) (actual time=0.050..1.000 rows=100 loops=1)
    -> Index lookup on orders using idx_user (user_id = 1)  (cost=50 rows=200) (actual time=0.010..0.500 rows=100 loops=1)
    -> Single-row index lookup on users using PRIMARY (id = orders.user_id)  (cost=1 rows=1) (actual time=0.001..0.001 rows=1 loops=100)
PLAN;

        $metrics = $this->parser->parse($plan);

        $perTable = $metrics['per_table_estimates'];
        $this->assertArrayHasKey('orders', $perTable);
        $this->assertArrayHasKey('users', $perTable);
        $this->assertSame(100, $perTable['orders']['actual_rows']);
        $this->assertSame(1, $perTable['users']['actual_rows']);
        $this->assertSame(100, $perTable['users']['loops']);
    }

    public function test_multiline_plan_nodes_handled(): void
    {
        // EXPLAIN ANALYZE can wrap long lines
        $plan = "-> Limit: 50 row(s)  (actual time=0.100..0.500 rows=50 loops=1)\n"
              ."    -> Index lookup on users using idx_name_email_status\n"
              ."        (name = 'test', email = 'test@test.com')  (cost=5 rows=10) (actual time=0.050..0.200 rows=10 loops=1)";

        $metrics = $this->parser->parse($plan);

        $this->assertSame(50, $metrics['rows_returned']);
        $this->assertContains('idx_name_email_status', $metrics['indexes_used']);
    }

    public function test_fanout_factor_calculated(): void
    {
        $plan = <<<'PLAN'
-> Nested loop inner join  (actual time=0.100..10.000 rows=500 loops=1)
    -> Index lookup on orders using idx_date (date > '2025-01-01')  (cost=50 rows=5000) (actual time=0.010..5.000 rows=5000 loops=1)
    -> Single-row index lookup on users using PRIMARY (id = orders.user_id)  (cost=1 rows=1) (actual time=0.001..0.001 rows=1 loops=5000)
PLAN;

        $metrics = $this->parser->parse($plan);

        $this->assertSame(5000.0, $metrics['fanout_factor']);
    }
}
