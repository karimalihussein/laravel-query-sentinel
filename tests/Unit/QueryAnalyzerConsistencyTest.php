<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QuerySentinel\Core\QueryAnalyzer;

/**
 * Tests the consistency validation and EXPLAIN enrichment logic
 * in QueryAnalyzer using reflection to access private methods.
 */
final class QueryAnalyzerConsistencyTest extends TestCase
{
    // ---------------------------------------------------------------
    // validateConsistency
    // ---------------------------------------------------------------

    public function test_index_backed_corrected_when_access_type_not_table_scan(): void
    {
        $metrics = [
            'primary_access_type' => 'index_lookup',
            'is_index_backed' => false,
            'is_zero_row_const' => false,
            'mysql_access_type' => 'ref',
            'rows_examined' => 100,
            'rows_returned' => 10,
            'has_table_scan' => false,
            'complexity' => 'O(log n)',
        ];

        $result = $this->callValidateConsistency($metrics);

        $this->assertTrue($result['is_index_backed']);
    }

    public function test_table_scan_stays_not_index_backed(): void
    {
        $metrics = [
            'primary_access_type' => 'table_scan',
            'is_index_backed' => false,
            'is_zero_row_const' => false,
            'mysql_access_type' => 'ALL',
            'rows_examined' => 50000,
            'rows_returned' => 50000,
            'has_table_scan' => true,
            'complexity' => 'O(n)',
        ];

        $result = $this->callValidateConsistency($metrics);

        $this->assertFalse($result['is_index_backed']);
    }

    public function test_zero_row_const_forces_constant_complexity(): void
    {
        $metrics = [
            'primary_access_type' => 'zero_row_const',
            'is_index_backed' => false,
            'is_zero_row_const' => true,
            'mysql_access_type' => 'const',
            'rows_examined' => 0,
            'rows_returned' => 0,
            'has_table_scan' => false,
            'complexity' => 'O(n)', // Wrong complexity
        ];

        $result = $this->callValidateConsistency($metrics);

        $this->assertSame('O(1)', $result['complexity']);
        $this->assertTrue($result['is_index_backed']);
    }

    public function test_zero_rows_examined_reduces_complexity(): void
    {
        $metrics = [
            'primary_access_type' => null,
            'is_index_backed' => true,
            'is_zero_row_const' => false,
            'mysql_access_type' => 'unknown',
            'rows_examined' => 0,
            'rows_returned' => 0,
            'has_table_scan' => false,
            'complexity' => 'O(n)', // Linear but 0 rows → should reduce
        ];

        $result = $this->callValidateConsistency($metrics);

        $this->assertSame('O(1)', $result['complexity']);
    }

    public function test_zero_rows_with_table_scan_not_reduced(): void
    {
        $metrics = [
            'primary_access_type' => 'table_scan',
            'is_index_backed' => false,
            'is_zero_row_const' => false,
            'mysql_access_type' => 'ALL',
            'rows_examined' => 0,
            'rows_returned' => 0,
            'has_table_scan' => true, // Table scan present, keep complexity
            'complexity' => 'O(n)',
        ];

        $result = $this->callValidateConsistency($metrics);

        // Table scan means keep original complexity even with 0 rows
        $this->assertSame('O(n)', $result['complexity']);
    }

    public function test_non_zero_rows_complexity_not_reduced(): void
    {
        $metrics = [
            'primary_access_type' => 'index_lookup',
            'is_index_backed' => true,
            'is_zero_row_const' => false,
            'mysql_access_type' => 'ref',
            'rows_examined' => 100,
            'rows_returned' => 50,
            'has_table_scan' => false,
            'complexity' => 'O(log n)',
        ];

        $result = $this->callValidateConsistency($metrics);

        // No contradiction → complexity unchanged
        $this->assertSame('O(log n)', $result['complexity']);
    }

    // ---------------------------------------------------------------
    // enrichMetricsFromExplain
    // ---------------------------------------------------------------

    public function test_enrich_from_explain_const_type(): void
    {
        $metrics = [
            'primary_access_type' => null,
            'mysql_access_type' => 'unknown',
            'is_index_backed' => false,
            'is_zero_row_const' => false,
            'indexes_used' => [],
            'has_covering_index' => false,
            'complexity' => 'O(n)',
            'complexity_label' => '',
            'complexity_risk' => '',
        ];

        $explainRows = [
            ['type' => 'const', 'key' => 'PRIMARY', 'Extra' => ''],
        ];

        $result = $this->callEnrichMetricsFromExplain($metrics, $explainRows);

        $this->assertSame('const_row', $result['primary_access_type']);
        $this->assertSame('const', $result['mysql_access_type']);
        $this->assertTrue($result['is_index_backed']);
        $this->assertSame('O(1)', $result['complexity']);
    }

    public function test_enrich_from_explain_no_matching_row_in_const_table(): void
    {
        $metrics = [
            'primary_access_type' => null,
            'mysql_access_type' => 'unknown',
            'is_index_backed' => false,
            'is_zero_row_const' => false,
            'indexes_used' => [],
            'has_covering_index' => false,
            'complexity' => 'O(n)',
            'complexity_label' => '',
            'complexity_risk' => '',
        ];

        $explainRows = [
            ['type' => 'const', 'key' => 'PRIMARY', 'Extra' => 'no matching row in const table'],
        ];

        $result = $this->callEnrichMetricsFromExplain($metrics, $explainRows);

        $this->assertTrue($result['is_zero_row_const']);
        $this->assertSame('const', $result['mysql_access_type']);
        $this->assertSame('O(1)', $result['complexity']);
    }

    public function test_enrich_from_explain_using_index_sets_covering(): void
    {
        $metrics = [
            'primary_access_type' => 'index_lookup',
            'mysql_access_type' => 'ref',
            'is_index_backed' => true,
            'is_zero_row_const' => false,
            'indexes_used' => ['idx_email'],
            'has_covering_index' => false,
            'complexity' => 'O(log n)',
            'complexity_label' => '',
            'complexity_risk' => '',
        ];

        $explainRows = [
            ['type' => 'ref', 'key' => 'idx_email', 'Extra' => 'Using index'],
        ];

        $result = $this->callEnrichMetricsFromExplain($metrics, $explainRows);

        $this->assertTrue($result['has_covering_index']);
    }

    public function test_enrich_from_explain_fallback_type_mapping(): void
    {
        $metrics = [
            'primary_access_type' => null,
            'mysql_access_type' => 'unknown',
            'is_index_backed' => false,
            'is_zero_row_const' => false,
            'indexes_used' => [],
            'has_covering_index' => false,
            'complexity' => 'O(n)',
            'complexity_label' => '',
            'complexity_risk' => '',
        ];

        // Test 'range' type fallback
        $explainRows = [
            ['type' => 'range', 'key' => 'idx_age', 'Extra' => ''],
        ];

        $result = $this->callEnrichMetricsFromExplain($metrics, $explainRows);

        $this->assertSame('index_range_scan', $result['primary_access_type']);
        $this->assertSame('range', $result['mysql_access_type']);
        $this->assertSame('O(log n + k)', $result['complexity']);
        $this->assertTrue($result['is_index_backed']);
    }

    public function test_enrich_from_explain_eq_ref_type_mapping(): void
    {
        $metrics = [
            'primary_access_type' => null,
            'mysql_access_type' => 'unknown',
            'is_index_backed' => false,
            'is_zero_row_const' => false,
            'indexes_used' => [],
            'has_covering_index' => false,
            'complexity' => 'O(n)',
            'complexity_label' => '',
            'complexity_risk' => '',
        ];

        $explainRows = [
            ['type' => 'eq_ref', 'key' => 'PRIMARY', 'Extra' => ''],
        ];

        $result = $this->callEnrichMetricsFromExplain($metrics, $explainRows);

        $this->assertSame('single_row_lookup', $result['primary_access_type']);
        $this->assertSame('eq_ref', $result['mysql_access_type']);
        $this->assertSame('O(1)', $result['complexity']);
    }

    public function test_enrich_from_explain_all_type_not_index_backed(): void
    {
        $metrics = [
            'primary_access_type' => null,
            'mysql_access_type' => 'unknown',
            'is_index_backed' => false,
            'is_zero_row_const' => false,
            'indexes_used' => [],
            'has_covering_index' => false,
            'complexity' => 'O(n)',
            'complexity_label' => '',
            'complexity_risk' => '',
        ];

        $explainRows = [
            ['type' => 'ALL', 'key' => null, 'Extra' => ''],
        ];

        $result = $this->callEnrichMetricsFromExplain($metrics, $explainRows);

        $this->assertSame('table_scan', $result['primary_access_type']);
        $this->assertSame('ALL', $result['mysql_access_type']);
        $this->assertFalse($result['is_index_backed']);
    }

    public function test_enrich_does_not_override_existing_access_type(): void
    {
        $metrics = [
            'primary_access_type' => 'index_lookup', // Already set
            'mysql_access_type' => 'ref',
            'is_index_backed' => true,
            'is_zero_row_const' => false,
            'indexes_used' => ['idx_email'],
            'has_covering_index' => false,
            'complexity' => 'O(log n)',
            'complexity_label' => '',
            'complexity_risk' => '',
        ];

        $explainRows = [
            ['type' => 'ALL', 'key' => null, 'Extra' => ''], // Would normally set table_scan
        ];

        $result = $this->callEnrichMetricsFromExplain($metrics, $explainRows);

        // Should NOT override — tree parser already detected access type
        $this->assertSame('index_lookup', $result['primary_access_type']);
    }

    public function test_enrich_empty_explain_rows_returns_unchanged(): void
    {
        $metrics = [
            'primary_access_type' => null,
            'complexity' => 'O(n)',
        ];

        $result = $this->callEnrichMetricsFromExplain($metrics, []);

        $this->assertNull($result['primary_access_type']);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $metrics
     * @return array<string, mixed>
     */
    private function callValidateConsistency(array $metrics): array
    {
        $analyzer = $this->createQueryAnalyzerStub();
        $method = new \ReflectionMethod($analyzer, 'validateConsistency');

        return $method->invoke($analyzer, $metrics);
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @param  array<int, array<string, mixed>>  $explainRows
     * @return array<string, mixed>
     */
    private function callEnrichMetricsFromExplain(array $metrics, array $explainRows): array
    {
        $analyzer = $this->createQueryAnalyzerStub();
        $method = new \ReflectionMethod($analyzer, 'enrichMetricsFromExplain');

        return $method->invoke($analyzer, $metrics, $explainRows);
    }

    private function createQueryAnalyzerStub(): QueryAnalyzer
    {
        $driver = $this->createMock(\QuerySentinel\Contracts\DriverInterface::class);
        $parser = $this->createMock(\QuerySentinel\Contracts\PlanParserInterface::class);
        $scoring = $this->createMock(\QuerySentinel\Contracts\ScoringEngineInterface::class);
        $registry = $this->createMock(\QuerySentinel\Contracts\RuleRegistryInterface::class);

        return new QueryAnalyzer($driver, $parser, $scoring, $registry);
    }
}
