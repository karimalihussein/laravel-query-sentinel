<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QuerySentinel\Contracts\DriverInterface;
use QuerySentinel\Drivers\MySqlDriver;
use QuerySentinel\Drivers\PostgresDriver;

final class MultiDriverNormalizationTest extends TestCase
{
    private MySqlDriver $mysql;

    private PostgresDriver $postgres;

    protected function setUp(): void
    {
        $this->mysql = new MySqlDriver;
        $this->postgres = new PostgresDriver;
    }

    // ─── MySQL normalizeAccessType ───────────────────────────────────

    public function test_mysql_const_normalizes_to_const_row(): void
    {
        $this->assertSame('const_row', $this->mysql->normalizeAccessType('const'));
    }

    public function test_mysql_system_normalizes_to_const_row(): void
    {
        $this->assertSame('const_row', $this->mysql->normalizeAccessType('system'));
    }

    public function test_mysql_eq_ref_normalizes_to_single_row_lookup(): void
    {
        $this->assertSame('single_row_lookup', $this->mysql->normalizeAccessType('eq_ref'));
    }

    public function test_mysql_ref_normalizes_to_index_lookup(): void
    {
        $this->assertSame('index_lookup', $this->mysql->normalizeAccessType('ref'));
    }

    public function test_mysql_ref_or_null_normalizes_to_index_lookup(): void
    {
        $this->assertSame('index_lookup', $this->mysql->normalizeAccessType('ref_or_null'));
    }

    public function test_mysql_range_normalizes_to_index_range_scan(): void
    {
        $this->assertSame('index_range_scan', $this->mysql->normalizeAccessType('range'));
    }

    public function test_mysql_index_normalizes_to_index_scan(): void
    {
        $this->assertSame('index_scan', $this->mysql->normalizeAccessType('index'));
    }

    public function test_mysql_all_normalizes_to_table_scan(): void
    {
        $this->assertSame('table_scan', $this->mysql->normalizeAccessType('ALL'));
    }

    public function test_mysql_index_merge_normalizes_to_index_merge(): void
    {
        $this->assertSame('index_merge', $this->mysql->normalizeAccessType('index_merge'));
    }

    public function test_mysql_unknown_access_type_normalizes_to_unknown(): void
    {
        $this->assertSame('unknown', $this->mysql->normalizeAccessType('something_weird'));
    }

    public function test_mysql_unique_subquery_normalizes_to_single_row_lookup(): void
    {
        $this->assertSame('single_row_lookup', $this->mysql->normalizeAccessType('unique_subquery'));
    }

    public function test_mysql_fulltext_normalizes_to_fulltext_lookup(): void
    {
        $this->assertSame('fulltext_lookup', $this->mysql->normalizeAccessType('fulltext'));
    }

    // ─── MySQL normalizeJoinType ─────────────────────────────────────

    public function test_mysql_inner_join_normalizes_to_inner(): void
    {
        $this->assertSame('inner', $this->mysql->normalizeJoinType('INNER JOIN'));
    }

    public function test_mysql_left_join_normalizes_to_left(): void
    {
        $this->assertSame('left', $this->mysql->normalizeJoinType('LEFT JOIN'));
    }

    public function test_mysql_cross_join_normalizes_to_cross(): void
    {
        $this->assertSame('cross', $this->mysql->normalizeJoinType('CROSS JOIN'));
    }

    public function test_mysql_straight_join_normalizes_to_forced_inner(): void
    {
        $this->assertSame('forced_inner', $this->mysql->normalizeJoinType('STRAIGHT_JOIN'));
    }

    public function test_mysql_right_join_normalizes_to_right(): void
    {
        $this->assertSame('right', $this->mysql->normalizeJoinType('RIGHT JOIN'));
    }

    public function test_mysql_right_outer_join_normalizes_to_right(): void
    {
        $this->assertSame('right', $this->mysql->normalizeJoinType('RIGHT OUTER JOIN'));
    }

    public function test_mysql_left_outer_join_normalizes_to_left(): void
    {
        $this->assertSame('left', $this->mysql->normalizeJoinType('LEFT OUTER JOIN'));
    }

    public function test_mysql_natural_join_normalizes_to_natural(): void
    {
        $this->assertSame('natural', $this->mysql->normalizeJoinType('NATURAL JOIN'));
    }

    public function test_mysql_join_normalizes_to_inner(): void
    {
        $this->assertSame('inner', $this->mysql->normalizeJoinType('JOIN'));
    }

    public function test_mysql_unknown_join_normalizes_to_unknown(): void
    {
        $this->assertSame('unknown', $this->mysql->normalizeJoinType('WEIRD JOIN'));
    }

    // ─── PostgreSQL normalizeAccessType ──────────────────────────────

    public function test_postgres_seq_scan_normalizes_to_table_scan(): void
    {
        $this->assertSame('table_scan', $this->postgres->normalizeAccessType('Seq Scan'));
    }

    public function test_postgres_index_scan_normalizes_to_index_lookup(): void
    {
        $this->assertSame('index_lookup', $this->postgres->normalizeAccessType('Index Scan'));
    }

    public function test_postgres_index_only_scan_normalizes_to_covering_index_lookup(): void
    {
        $this->assertSame('covering_index_lookup', $this->postgres->normalizeAccessType('Index Only Scan'));
    }

    public function test_postgres_bitmap_index_scan_normalizes_to_index_range_scan(): void
    {
        $this->assertSame('index_range_scan', $this->postgres->normalizeAccessType('Bitmap Index Scan'));
    }

    public function test_postgres_bitmap_heap_scan_normalizes_to_index_range_scan(): void
    {
        $this->assertSame('index_range_scan', $this->postgres->normalizeAccessType('Bitmap Heap Scan'));
    }

    public function test_postgres_tid_scan_normalizes_to_single_row_lookup(): void
    {
        $this->assertSame('single_row_lookup', $this->postgres->normalizeAccessType('Tid Scan'));
    }

    public function test_postgres_values_scan_normalizes_to_const_row(): void
    {
        $this->assertSame('const_row', $this->postgres->normalizeAccessType('Values Scan'));
    }

    public function test_postgres_unknown_access_type_normalizes_to_unknown(): void
    {
        $this->assertSame('unknown', $this->postgres->normalizeAccessType('Something Else'));
    }

    // ─── PostgreSQL normalizeJoinType ────────────────────────────────

    public function test_postgres_nested_loop_normalizes_to_nested_loop(): void
    {
        $this->assertSame('nested_loop', $this->postgres->normalizeJoinType('Nested Loop'));
    }

    public function test_postgres_hash_join_normalizes_to_hash(): void
    {
        $this->assertSame('hash', $this->postgres->normalizeJoinType('Hash Join'));
    }

    public function test_postgres_merge_join_normalizes_to_merge(): void
    {
        $this->assertSame('merge', $this->postgres->normalizeJoinType('Merge Join'));
    }

    public function test_postgres_unknown_join_normalizes_to_unknown(): void
    {
        $this->assertSame('unknown', $this->postgres->normalizeJoinType('Something Else'));
    }

    // ─── Capabilities ────────────────────────────────────────────────

    public function test_mysql_capabilities_include_expected_keys(): void
    {
        $capabilities = $this->mysql->getCapabilities();

        $this->assertArrayHasKey('histograms', $capabilities);
        $this->assertArrayHasKey('explain_analyze', $capabilities);
        $this->assertArrayHasKey('json_explain', $capabilities);
        $this->assertArrayHasKey('covering_index_info', $capabilities);
        $this->assertArrayHasKey('parallel_query', $capabilities);
    }

    public function test_mysql_capabilities_has_parallel_query_false(): void
    {
        $this->assertFalse($this->mysql->getCapabilities()['parallel_query']);
    }

    public function test_mysql_capabilities_has_json_explain_true(): void
    {
        $this->assertTrue($this->mysql->getCapabilities()['json_explain']);
    }

    public function test_postgres_capabilities_include_expected_keys(): void
    {
        $capabilities = $this->postgres->getCapabilities();

        $this->assertArrayHasKey('histograms', $capabilities);
        $this->assertArrayHasKey('explain_analyze', $capabilities);
        $this->assertArrayHasKey('json_explain', $capabilities);
        $this->assertArrayHasKey('covering_index_info', $capabilities);
        $this->assertArrayHasKey('parallel_query', $capabilities);
    }

    public function test_postgres_capabilities_has_parallel_query_true(): void
    {
        $this->assertTrue($this->postgres->getCapabilities()['parallel_query']);
    }

    public function test_postgres_capabilities_has_histograms_true(): void
    {
        $this->assertTrue($this->postgres->getCapabilities()['histograms']);
    }

    public function test_postgres_capabilities_has_explain_analyze_true(): void
    {
        $this->assertTrue($this->postgres->getCapabilities()['explain_analyze']);
    }

    // ─── Interface contract ──────────────────────────────────────────

    public function test_driver_interface_declares_all_phase11_methods(): void
    {
        $reflection = new \ReflectionClass(DriverInterface::class);

        $this->assertTrue($reflection->hasMethod('normalizeAccessType'));
        $this->assertTrue($reflection->hasMethod('normalizeJoinType'));
        $this->assertTrue($reflection->hasMethod('runAnalyzeTable'));
        $this->assertTrue($reflection->hasMethod('getColumnStats'));
        $this->assertTrue($reflection->hasMethod('getCapabilities'));
    }

    public function test_mysql_driver_implements_driver_interface(): void
    {
        $this->assertInstanceOf(DriverInterface::class, $this->mysql);
    }

    public function test_postgres_driver_implements_driver_interface(): void
    {
        $this->assertInstanceOf(DriverInterface::class, $this->postgres);
    }

    // ─── Case insensitivity ──────────────────────────────────────────

    public function test_mysql_access_type_is_case_insensitive(): void
    {
        $this->assertSame('table_scan', $this->mysql->normalizeAccessType('ALL'));
        $this->assertSame('table_scan', $this->mysql->normalizeAccessType('all'));
        $this->assertSame('table_scan', $this->mysql->normalizeAccessType('All'));
    }

    public function test_mysql_join_type_is_case_insensitive(): void
    {
        $this->assertSame('inner', $this->mysql->normalizeJoinType('inner join'));
        $this->assertSame('inner', $this->mysql->normalizeJoinType('INNER JOIN'));
        $this->assertSame('inner', $this->mysql->normalizeJoinType('Inner Join'));
    }

    public function test_postgres_access_type_is_case_insensitive(): void
    {
        $this->assertSame('table_scan', $this->postgres->normalizeAccessType('SEQ SCAN'));
        $this->assertSame('table_scan', $this->postgres->normalizeAccessType('seq scan'));
        $this->assertSame('table_scan', $this->postgres->normalizeAccessType('Seq Scan'));
    }

    public function test_postgres_join_type_is_case_insensitive(): void
    {
        $this->assertSame('hash', $this->postgres->normalizeJoinType('HASH JOIN'));
        $this->assertSame('hash', $this->postgres->normalizeJoinType('hash join'));
        $this->assertSame('hash', $this->postgres->normalizeJoinType('Hash Join'));
    }
}
