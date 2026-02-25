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

    public function test_complexity_classification_logarithmic(): void
    {
        $plan = <<<'PLAN'
-> Limit: 50 row(s)  (cost=100 rows=10000) (actual time=0.050..0.100 rows=50 loops=1)
    -> Covering index lookup on users using idx_status (status = 1)  (cost=50 rows=10000) (actual time=0.040..0.090 rows=50 loops=1)
PLAN;

        $metrics = $this->parser->parse($plan);

        // Covering index lookup → O(log n) (Logarithmic)
        $this->assertSame('O(log n)', $metrics['complexity']);
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

    public function test_zero_row_const_detection(): void
    {
        $plan = <<<'PLAN'
-> Zero rows (no matching row in const table)  (cost=0..0 rows=0) (actual time=0.00279..0.00279 rows=0 loops=1)
PLAN;

        $metrics = $this->parser->parse($plan);

        $this->assertTrue($metrics['is_zero_row_const']);
        $this->assertTrue($metrics['is_index_backed']);
        $this->assertSame('O(1)', $metrics['complexity']);
        $this->assertSame('const', $metrics['mysql_access_type']);
        $this->assertSame('zero_row_const', $metrics['primary_access_type']);
        $this->assertSame(0, $metrics['rows_examined']);
        $this->assertSame(0, $metrics['rows_returned']);
    }

    public function test_const_row_detection(): void
    {
        $plan = <<<'PLAN'
-> Constant row from users  (cost=0..0 rows=1) (actual time=0.015..0.016 rows=1 loops=1)
PLAN;

        $metrics = $this->parser->parse($plan);

        $this->assertTrue($metrics['is_index_backed']);
        $this->assertSame('O(1)', $metrics['complexity']);
        $this->assertSame('const', $metrics['mysql_access_type']);
        $this->assertSame('const_row', $metrics['primary_access_type']);
    }

    public function test_single_row_lookup_is_constant(): void
    {
        $plan = <<<'PLAN'
-> Single-row index lookup on users using PRIMARY (id=1)  (cost=1 rows=1) (actual time=0.010..0.010 rows=1 loops=1)
PLAN;

        $metrics = $this->parser->parse($plan);

        $this->assertTrue($metrics['is_index_backed']);
        $this->assertSame('O(1)', $metrics['complexity']);
        $this->assertSame('eq_ref', $metrics['mysql_access_type']);
    }

    public function test_index_range_scan_complexity(): void
    {
        $plan = <<<'PLAN'
-> Index range scan on users using idx_age over (20 < age < 30)  (cost=50 rows=500) (actual time=0.050..0.500 rows=500 loops=1)
PLAN;

        $metrics = $this->parser->parse($plan);

        $this->assertSame('O(log n + k)', $metrics['complexity']);
        $this->assertSame('range', $metrics['mysql_access_type']);
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

    public function test_metrics_contain_access_type_fields(): void
    {
        $plan = <<<'PLAN'
-> Index lookup on users using idx_email (email = 'test@example.com')  (cost=1 rows=1) (actual time=0.010..0.010 rows=1 loops=1)
PLAN;

        $metrics = $this->parser->parse($plan);

        $this->assertArrayHasKey('primary_access_type', $metrics);
        $this->assertArrayHasKey('mysql_access_type', $metrics);
        $this->assertArrayHasKey('is_zero_row_const', $metrics);
        $this->assertSame('index_lookup', $metrics['primary_access_type']);
        $this->assertSame('ref', $metrics['mysql_access_type']);
        $this->assertFalse($metrics['is_zero_row_const']);
    }

    // ---------------------------------------------------------------
    // Additional access type coverage
    // ---------------------------------------------------------------

    public function test_index_scan_classified_as_linear(): void
    {
        $plan = <<<'PLAN'
-> Index scan on users using idx_name  (cost=500 rows=50000) (actual time=0.100..200.000 rows=50000 loops=1)
PLAN;

        $metrics = $this->parser->parse($plan);

        $this->assertSame('O(n)', $metrics['complexity']);
        $this->assertSame('index', $metrics['mysql_access_type']);
        $this->assertSame('index_scan', $metrics['primary_access_type']);
        $this->assertTrue($metrics['is_index_backed']);
    }

    public function test_fulltext_index_detection(): void
    {
        $plan = <<<'PLAN'
-> Full-text index search on articles using idx_ft_content  (cost=50 rows=100) (actual time=0.500..1.000 rows=100 loops=1)
PLAN;

        $metrics = $this->parser->parse($plan);

        $this->assertSame('fulltext_index', $metrics['primary_access_type']);
        $this->assertSame('fulltext', $metrics['mysql_access_type']);
        $this->assertTrue($metrics['is_index_backed']);
        $this->assertSame('O(log n)', $metrics['complexity']);
    }

    public function test_rows_fetched_before_execution_is_const(): void
    {
        $plan = <<<'PLAN'
-> Rows fetched before execution  (cost=0..0 rows=1) (actual time=0.000..0.001 rows=1 loops=1)
PLAN;

        $metrics = $this->parser->parse($plan);

        $this->assertSame('const_row', $metrics['primary_access_type']);
        $this->assertSame('const', $metrics['mysql_access_type']);
        $this->assertSame('O(1)', $metrics['complexity']);
    }

    public function test_single_row_covering_index_lookup(): void
    {
        $plan = <<<'PLAN'
-> Single-row covering index lookup on users using PRIMARY (id=1)  (cost=1 rows=1) (actual time=0.005..0.005 rows=1 loops=1)
PLAN;

        $metrics = $this->parser->parse($plan);

        $this->assertSame('single_row_lookup', $metrics['primary_access_type']);
        $this->assertSame('eq_ref', $metrics['mysql_access_type']);
        $this->assertSame('O(1)', $metrics['complexity']);
    }

    public function test_filesort_with_index_lookup_classified_linearithmic(): void
    {
        $plan = <<<'PLAN'
-> Sort: users.created_at DESC, limit input to 50 row(s) per chunk  (actual time=2.000..2.100 rows=50 loops=1)
    -> Index lookup on users using idx_status (status = 1)  (cost=50 rows=1000) (actual time=0.050..1.500 rows=1000 loops=1)
PLAN;

        $metrics = $this->parser->parse($plan);

        $this->assertTrue($metrics['has_filesort']);
        $this->assertSame('O(n log n)', $metrics['complexity']);
    }

    public function test_mysql_access_type_mappings(): void
    {
        // zero_row_const → const
        $plan = '-> Zero rows (no matching row in const table)  (cost=0..0 rows=0) (actual time=0.003..0.003 rows=0 loops=1)';
        $this->assertSame('const', $this->parser->parse($plan)['mysql_access_type']);

        // const_row → const
        $plan = '-> Constant row from users  (cost=0..0 rows=1) (actual time=0.015..0.016 rows=1 loops=1)';
        $this->assertSame('const', $this->parser->parse($plan)['mysql_access_type']);

        // single_row_lookup → eq_ref
        $plan = '-> Single-row index lookup on users using PRIMARY (id=1)  (cost=1 rows=1) (actual time=0.010..0.010 rows=1 loops=1)';
        $this->assertSame('eq_ref', $this->parser->parse($plan)['mysql_access_type']);

        // covering_index_lookup → ref
        $plan = '-> Covering index lookup on users using idx_cover (status = 1)  (cost=5 rows=100) (actual time=0.050..0.100 rows=100 loops=1)';
        $this->assertSame('ref', $this->parser->parse($plan)['mysql_access_type']);

        // index_lookup → ref
        $plan = "-> Index lookup on users using idx_name (name = 'test')  (cost=5 rows=100) (actual time=0.050..0.100 rows=100 loops=1)";
        $this->assertSame('ref', $this->parser->parse($plan)['mysql_access_type']);

        // index_range_scan → range
        $plan = '-> Index range scan on users using idx_age over (20 < age < 30)  (cost=50 rows=500) (actual time=0.050..0.500 rows=500 loops=1)';
        $this->assertSame('range', $this->parser->parse($plan)['mysql_access_type']);

        // index_scan → index
        $plan = '-> Index scan on users using idx_name  (cost=500 rows=50000) (actual time=0.100..200.000 rows=50000 loops=1)';
        $this->assertSame('index', $this->parser->parse($plan)['mysql_access_type']);

        // table_scan → ALL
        $plan = '-> Table scan on users  (cost=100 rows=50000) (actual time=0.100..150.000 rows=50000 loops=1)';
        $this->assertSame('ALL', $this->parser->parse($plan)['mysql_access_type']);
    }

    public function test_table_extracted_from_constant_row(): void
    {
        $plan = <<<'PLAN'
-> Constant row from settings  (cost=0..0 rows=1) (actual time=0.010..0.010 rows=1 loops=1)
PLAN;

        $metrics = $this->parser->parse($plan);

        $this->assertContains('settings', $metrics['tables_accessed']);
    }

    public function test_complexity_not_overridden_by_temp_table_when_already_linear(): void
    {
        // table_scan is already O(n), temp table doesn't change it
        $plan = <<<'PLAN'
-> Table scan on <temporary>  (actual time=0.100..0.200 rows=100 loops=1)
    -> Materialize  (actual time=10.000..10.100 rows=100 loops=1)
        -> Table scan on users  (cost=100 rows=50000) (actual time=0.100..8.000 rows=50000 loops=1)
PLAN;

        $metrics = $this->parser->parse($plan);

        // Already O(n) from table scan — temp table doesn't raise further
        $this->assertSame('O(n)', $metrics['complexity']);
    }

    public function test_deep_nested_loop_with_high_loops_classified_quadratic(): void
    {
        $plan = <<<'PLAN'
-> Nested loop inner join  (cost=1000 rows=10000) (actual time=0.1..100.0 rows=10000 loops=1)
    -> Nested loop inner join  (cost=500 rows=1000) (actual time=0.1..50.0 rows=1000 loops=1)
        -> Nested loop inner join  (cost=100 rows=100) (actual time=0.1..10.0 rows=100 loops=1)
            -> Nested loop inner join  (cost=50 rows=10) (actual time=0.1..5.0 rows=10 loops=1)
                -> Index lookup on a using idx_a (id = 1)  (cost=5 rows=10) (actual time=0.01..0.1 rows=10 loops=1)
                -> Index lookup on b using idx_b (a_id = a.id)  (cost=1 rows=10) (actual time=0.001..0.001 rows=10 loops=10000)
PLAN;

        $metrics = $this->parser->parse($plan);

        // 4 nested loops + max_loops > 1000 → quadratic
        $this->assertSame('O(n²)', $metrics['complexity']);
    }

    public function test_empty_plan_produces_correct_default_access_type(): void
    {
        $metrics = $this->parser->parse('');

        $this->assertNull($metrics['primary_access_type']);
        $this->assertSame('unknown', $metrics['mysql_access_type']);
        $this->assertFalse($metrics['is_zero_row_const']);
        $this->assertFalse($metrics['is_index_backed']);
    }

    // ---------------------------------------------------------------
    // Scientific notation parsing
    // ---------------------------------------------------------------

    public function test_scientific_notation_rows_parsed_correctly(): void
    {
        $plan = <<<'PLAN'
-> Table scan on users  (cost=102876 rows=993739) (actual time=0.1..214 rows=1e+6 loops=1)
PLAN;

        $metrics = $this->parser->parse($plan);

        $this->assertSame(214.0, $metrics['execution_time_ms']);
        $this->assertSame(1000000, $metrics['rows_returned']);
        $this->assertSame(1000000, $metrics['rows_examined']);
        $this->assertTrue($metrics['has_table_scan']);
        $this->assertTrue($metrics['parsing_valid']);
    }

    public function test_scientific_notation_large_rows(): void
    {
        $plan = <<<'PLAN'
-> Table scan on events  (cost=500000 rows=5e+6) (actual time=0.5..1500 rows=1e+7 loops=1)
PLAN;

        $metrics = $this->parser->parse($plan);

        $this->assertSame(1500.0, $metrics['execution_time_ms']);
        $this->assertSame(10000000, $metrics['rows_returned']);
        $this->assertSame(10000000, $metrics['rows_examined']);
    }

    public function test_scientific_notation_with_decimal(): void
    {
        $plan = <<<'PLAN'
-> Index lookup on orders using idx_user (user_id = 1)  (cost=50 rows=1.5e+3) (actual time=0.05..5.0 rows=1.5e+3 loops=1)
PLAN;

        $metrics = $this->parser->parse($plan);

        $this->assertSame(5.0, $metrics['execution_time_ms']);
        $this->assertSame(1500, $metrics['rows_returned']);
        $this->assertSame(1500, $metrics['rows_examined']);
    }

    public function test_scientific_notation_in_loops(): void
    {
        $plan = <<<'PLAN'
-> Nested loop inner join  (cost=1000 rows=50000) (actual time=0.1..100.0 rows=50000 loops=1)
    -> Table scan on a  (cost=10 rows=50000) (actual time=0.1..10.0 rows=50000 loops=1)
    -> Single-row index lookup on b using PRIMARY (id = a.b_id)  (cost=1 rows=1) (actual time=0.001..0.001 rows=1 loops=5e+4)
PLAN;

        $metrics = $this->parser->parse($plan);

        // b: 1 row * 50000 loops = 50000 rows examined
        $this->assertSame(100000, $metrics['rows_examined']); // a: 50000 + b: 50000
    }

    public function test_parsing_valid_flag_set_when_actual_metrics_present(): void
    {
        $plan = '-> Table scan on users  (cost=100 rows=50000) (actual time=0.100..150.000 rows=50000 loops=1)';
        $metrics = $this->parser->parse($plan);
        $this->assertTrue($metrics['parsing_valid']);
    }

    public function test_parsing_valid_flag_false_for_empty_plan(): void
    {
        $metrics = $this->parser->parse('');
        $this->assertFalse($metrics['parsing_valid']);
    }
}
