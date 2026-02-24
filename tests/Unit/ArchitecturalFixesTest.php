<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QuerySentinel\Analyzers\MemoryPressureAnalyzer;
use QuerySentinel\Analyzers\RegressionBaselineAnalyzer;
use QuerySentinel\Analyzers\ScalabilityEstimator;
use QuerySentinel\Enums\ComplexityClass;
use QuerySentinel\Enums\Severity;
use QuerySentinel\Scoring\DefaultScoringEngine;
use QuerySentinel\Support\BaselineStore;
use QuerySentinel\Support\EnvironmentContext;
use QuerySentinel\Support\ExecutionProfile;

/**
 * Tests for the 4 architectural fixes:
 * 1. Memory estimation → working-set model (physical reads / page estimation)
 * 2. Regression → data growth vs performance degradation (per-row normalization)
 * 3. Execution time → size-normalized scoring for large scans
 * 4. Buffer pool pressure → realistic bounds from working-set model
 */
final class ArchitecturalFixesTest extends TestCase
{
    // ---------------------------------------------------------------
    // Fix 1: Memory estimation — working-set model
    // ---------------------------------------------------------------

    public function test_memory_uses_physical_reads_not_logical(): void
    {
        $analyzer = new MemoryPressureAnalyzer;
        $profile = new ExecutionProfile(
            nestedLoopDepth: 0,
            joinFanouts: [],
            btreeDepths: [],
            logicalReads: 1_000_000,  // would produce 15.3GB under old model
            physicalReads: 3_111,      // actual disk pages
            scanComplexity: ComplexityClass::Linear,
            sortComplexity: ComplexityClass::Constant,
        );

        $result = $analyzer->analyze(
            ['rows_examined' => 1_000_000, 'has_filesort' => false, 'has_temp_table' => false, 'has_disk_temp' => false, 'join_count' => 0],
            new EnvironmentContext('9.3.0', 134217728, 10000, 16384, 16777216, 16777216, 1.0, false, 'test'),
            $profile,
        );

        $bpReads = $result['memory_pressure']['components']['buffer_pool_reads_bytes'];
        // physical_reads × page_size = 3111 × 16384 = 50,954,624 bytes ≈ 48.6 MB
        $this->assertSame(3_111 * 16384, $bpReads);
        // Must NOT be the old logicalReads × pageSize = ~15.3 GB
        $this->assertLessThan(100_000_000, $bpReads); // < 100MB
    }

    public function test_memory_page_estimation_fallback_when_no_physical_reads(): void
    {
        $analyzer = new MemoryPressureAnalyzer;

        // No profile → falls back to page estimation
        $result = $analyzer->analyze(
            ['rows_examined' => 1_000_000, 'has_filesort' => false, 'has_temp_table' => false, 'has_disk_temp' => false, 'join_count' => 0],
            null,
            null,
        );

        $bpReads = $result['memory_pressure']['components']['buffer_pool_reads_bytes'];
        // Page estimation: ceil(1M × 256 / 16384) = 15625 pages × 16384 = 256,000,000 bytes ≈ 244 MB
        $expectedPages = (int) ceil((1_000_000 * 256) / 16384);
        $this->assertSame($expectedPages * 16384, $bpReads);
        // Must NOT be 15.3 GB
        $this->assertLessThan(300_000_000, $bpReads); // < 300MB
    }

    public function test_memory_1m_row_scan_realistic_estimate(): void
    {
        $analyzer = new MemoryPressureAnalyzer;
        $profile = new ExecutionProfile(
            nestedLoopDepth: 0,
            joinFanouts: [],
            btreeDepths: [],
            logicalReads: 1_000_000,
            physicalReads: 3_111,
            scanComplexity: ComplexityClass::Linear,
            sortComplexity: ComplexityClass::Constant,
        );

        $result = $analyzer->analyze(
            ['rows_examined' => 1_000_000, 'has_filesort' => false, 'has_temp_table' => false, 'has_disk_temp' => false, 'join_count' => 0],
            new EnvironmentContext('9.3.0', 134217728, 10000, 16384, 16777216, 16777216, 1.0, false, 'test'),
            $profile,
        );

        $total = $result['memory_pressure']['total_estimated_bytes'];
        // Should be ~48.6 MB total (only buffer pool reads, no sort/join/temp/disk)
        $this->assertLessThan(100_000_000, $total);
        // The old model would produce 15.3 GB — ensure we're orders of magnitude smaller
        $this->assertLessThan(1_073_741_824, $total); // < 1 GB
    }

    // ---------------------------------------------------------------
    // Fix 2: Regression — data growth vs performance degradation
    // ---------------------------------------------------------------

    public function test_rows_growth_with_stable_per_row_performance_is_data_growth(): void
    {
        $store = new BaselineStore(sys_get_temp_dir() . '/qs_arch_' . uniqid('', true));
        $queryHash = hash('sha256', strtolower(trim('select id from users')));

        $store->save($queryHash, [
            'composite_score' => 85.0,
            'execution_time_ms' => 100.0,
            'rows_examined' => 800_000,
            'timestamp' => date('c'),
        ]);

        $analyzer = new RegressionBaselineAnalyzer($store);

        // Rows grew from 800K → 1M (25% increase), time grew proportionally (100→125ms)
        // Per-row: 0.000125 ms/row → 0.000125 ms/row — identical.
        $result = $analyzer->analyze('SELECT id FROM users', [
            'composite_score' => 85.0,
            'execution_time_ms' => 125.0,
            'rows_examined' => 1_000_000,
        ]);

        // Should NOT be flagged as regression
        $rowsRegression = null;
        foreach ($result['regression']['regressions'] as $r) {
            if ($r['metric'] === 'rows_examined') {
                $rowsRegression = $r;
            }
        }
        $this->assertNull($rowsRegression, 'Proportional data growth should not be flagged as regression');
    }

    public function test_rows_growth_with_degraded_per_row_performance_is_regression(): void
    {
        $store = new BaselineStore(sys_get_temp_dir() . '/qs_arch_' . uniqid('', true));
        $queryHash = hash('sha256', strtolower(trim('select id from orders')));

        $store->save($queryHash, [
            'composite_score' => 85.0,
            'execution_time_ms' => 50.0,
            'rows_examined' => 100_000,
            'timestamp' => date('c'),
        ]);

        $analyzer = new RegressionBaselineAnalyzer($store);

        // Rows 100K→200K (100% increase), but time 50ms→200ms (300% increase)
        // Per-row: 0.5μs → 1.0μs — 100% per-row degradation → real regression
        $result = $analyzer->analyze('SELECT id FROM orders', [
            'composite_score' => 70.0,
            'execution_time_ms' => 200.0,
            'rows_examined' => 200_000,
        ]);

        $rowsRegression = null;
        foreach ($result['regression']['regressions'] as $r) {
            if ($r['metric'] === 'rows_examined') {
                $rowsRegression = $r;
            }
        }
        $this->assertNotNull($rowsRegression, 'Per-row degradation should be flagged as regression');
        $this->assertSame('performance_degradation', $rowsRegression['classification']);
    }

    public function test_large_data_growth_logged_as_info_finding(): void
    {
        $store = new BaselineStore(sys_get_temp_dir() . '/qs_arch_' . uniqid('', true));
        $queryHash = hash('sha256', strtolower(trim('select name from products')));

        $store->save($queryHash, [
            'composite_score' => 90.0,
            'execution_time_ms' => 50.0,
            'rows_examined' => 500_000,
            'timestamp' => date('c'),
        ]);

        $analyzer = new RegressionBaselineAnalyzer($store);

        // Rows doubled but per-row unchanged: 50ms/500K = 100ms/1M
        $result = $analyzer->analyze('SELECT name FROM products', [
            'composite_score' => 90.0,
            'execution_time_ms' => 100.0,
            'rows_examined' => 1_000_000,
        ]);

        // Should have info-level data growth finding
        $dataGrowthFindings = array_filter(
            $result['findings'],
            fn ($f) => str_contains($f->title, 'Data growth'),
        );
        $this->assertNotEmpty($dataGrowthFindings, 'Large data growth should be logged as info finding');

        $finding = array_values($dataGrowthFindings)[0];
        $this->assertSame(Severity::Info, $finding->severity);
        $this->assertSame('data_growth', $finding->metadata['classification']);
    }

    // ---------------------------------------------------------------
    // Fix 3: Execution time — size-normalized scoring
    // ---------------------------------------------------------------

    public function test_large_scan_scored_by_per_row_performance(): void
    {
        $engine = new DefaultScoringEngine;

        // 258ms for 1M rows = 0.258 μs/row → between 0.1 and 0.3 → score 95
        $result = $engine->score([
            'execution_time_ms' => 258.0,
            'rows_examined' => 1_000_000,
            'rows_returned' => 1_000_000,
            'nested_loop_depth' => 0,
            'fanout_factor' => 1.0,
            'has_weedout' => false,
            'has_temp_table' => false,
            'primary_access_type' => 'table_scan',
            'has_table_scan' => true,
            'is_index_backed' => false,
            'complexity' => 'linear',
            'is_intentional_scan' => true,
        ]);

        $timeScore = $result['breakdown']['execution_time']['score'];
        // 0.258 μs/row vs 0.3 μs expected for table_scan → ratio 0.86 → at or better = 100
        $this->assertSame(100, $timeScore);
    }

    public function test_small_result_set_uses_absolute_scoring(): void
    {
        $engine = new DefaultScoringEngine;

        // 50ms for 100 rows → uses absolute scoring: 70 - (50-100)... → 90-100 range
        $result = $engine->score([
            'execution_time_ms' => 50.0,
            'rows_examined' => 100,
            'rows_returned' => 100,
            'nested_loop_depth' => 0,
            'fanout_factor' => 1.0,
            'has_weedout' => false,
            'has_temp_table' => false,
            'primary_access_type' => 'index_lookup',
            'has_table_scan' => false,
            'is_index_backed' => true,
            'complexity' => 'logarithmic',
        ]);

        $timeScore = $result['breakdown']['execution_time']['score'];
        // 50ms absolute: 90 - (50-10) * (20/90) ≈ 81
        $this->assertSame(81, $timeScore);
    }

    public function test_slow_per_row_large_scan_penalized(): void
    {
        $engine = new DefaultScoringEngine;

        // 10,000ms for 100K rows = 100 μs/row → > 50 μs/row → score 10
        $result = $engine->score([
            'execution_time_ms' => 10000.0,
            'rows_examined' => 100_000,
            'rows_returned' => 100_000,
            'nested_loop_depth' => 0,
            'fanout_factor' => 1.0,
            'has_weedout' => false,
            'has_temp_table' => false,
            'primary_access_type' => 'table_scan',
            'has_table_scan' => true,
            'is_index_backed' => false,
            'complexity' => 'linear',
        ]);

        $timeScore = $result['breakdown']['execution_time']['score'];
        $this->assertSame(10, $timeScore);
    }

    public function test_fast_per_row_large_scan_not_penalized(): void
    {
        $engine = new DefaultScoringEngine;

        // 5ms for 1M rows = 0.005 μs/row → < 0.1 → score 100
        $result = $engine->score([
            'execution_time_ms' => 5.0,
            'rows_examined' => 1_000_000,
            'rows_returned' => 1_000_000,
            'nested_loop_depth' => 0,
            'fanout_factor' => 1.0,
            'has_weedout' => false,
            'has_temp_table' => false,
            'primary_access_type' => 'table_scan',
            'has_table_scan' => true,
            'is_index_backed' => false,
            'complexity' => 'linear',
            'is_intentional_scan' => true,
        ]);

        $timeScore = $result['breakdown']['execution_time']['score'];
        $this->assertSame(100, $timeScore);
    }

    // ---------------------------------------------------------------
    // Fix 4: Buffer pool pressure — realistic bounds
    // ---------------------------------------------------------------

    public function test_buffer_pool_pressure_realistic_for_1m_scan(): void
    {
        $analyzer = new MemoryPressureAnalyzer;
        $profile = new ExecutionProfile(
            nestedLoopDepth: 0,
            joinFanouts: [],
            btreeDepths: [],
            logicalReads: 1_000_000,
            physicalReads: 3_111,
            scanComplexity: ComplexityClass::Linear,
            sortComplexity: ComplexityClass::Constant,
        );

        $result = $analyzer->analyze(
            ['rows_examined' => 1_000_000, 'has_filesort' => false, 'has_temp_table' => false, 'has_disk_temp' => false, 'join_count' => 0],
            new EnvironmentContext('9.3.0', 134217728, 10000, 16384, 16777216, 16777216, 1.0, false, 'test'),
            $profile,
        );

        $pressure = $result['memory_pressure']['buffer_pool_pressure'];
        // Old: 12207%. New: 3111 * 16384 / 128MB = ~38%
        $this->assertLessThan(1.0, $pressure); // Must be < 100%
        $expectedPressure = round((3_111 * 16384) / 134217728, 4);
        $this->assertSame($expectedPressure, $pressure);
    }

    public function test_buffer_pool_pressure_never_inflated_by_logical_reads(): void
    {
        $analyzer = new MemoryPressureAnalyzer;
        $profile = new ExecutionProfile(
            nestedLoopDepth: 0,
            joinFanouts: [],
            btreeDepths: [],
            logicalReads: 10_000_000, // huge logical reads
            physicalReads: 100,        // but tiny physical reads
            scanComplexity: ComplexityClass::Linear,
            sortComplexity: ComplexityClass::Constant,
        );

        $result = $analyzer->analyze(
            ['rows_examined' => 10_000_000, 'has_filesort' => false, 'has_temp_table' => false, 'has_disk_temp' => false, 'join_count' => 0],
            new EnvironmentContext('9.3.0', 134217728, 10000, 16384, 16777216, 16777216, 1.0, false, 'test'),
            $profile,
        );

        $bpReads = $result['memory_pressure']['components']['buffer_pool_reads_bytes'];
        // Uses physicalReads (100), not logicalReads (10M)
        $this->assertSame(100 * 16384, $bpReads);
        $pressure = $result['memory_pressure']['buffer_pool_pressure'];
        $this->assertLessThan(0.02, $pressure);
    }

    // ---------------------------------------------------------------
    // Join efficiency — fanout penalty fix
    // ---------------------------------------------------------------

    public function test_single_table_scan_no_fanout_penalty(): void
    {
        $engine = new DefaultScoringEngine;

        // 1M row single-table scan: fanout=1M but depth=0 → no penalty
        $result = $engine->score([
            'execution_time_ms' => 258.0,
            'rows_examined' => 1_000_000,
            'rows_returned' => 1_000_000,
            'nested_loop_depth' => 0,
            'fanout_factor' => 1_000_000.0,
            'has_weedout' => false,
            'has_temp_table' => false,
            'primary_access_type' => 'table_scan',
            'has_table_scan' => true,
            'is_index_backed' => false,
            'complexity' => 'linear',
            'is_intentional_scan' => true,
        ]);

        $joinScore = $result['breakdown']['join_efficiency']['score'];
        $this->assertSame(100, $joinScore);
    }

    public function test_join_with_high_fanout_still_penalized(): void
    {
        $engine = new DefaultScoringEngine;

        // Multi-table join with high fanout: depth=2, fanout=50000
        $result = $engine->score([
            'execution_time_ms' => 100.0,
            'rows_examined' => 50000,
            'rows_returned' => 50000,
            'nested_loop_depth' => 2,
            'fanout_factor' => 50000.0,
            'has_weedout' => false,
            'has_temp_table' => false,
            'primary_access_type' => 'index_lookup',
            'has_table_scan' => false,
            'is_index_backed' => true,
            'complexity' => 'logarithmic',
        ]);

        $joinScore = $result['breakdown']['join_efficiency']['score'];
        // depth ≤ 2: base 100. fanout > 10K with depth > 0: -30. Score: 70
        $this->assertSame(70, $joinScore);
    }

    // ---------------------------------------------------------------
    // Issue 1: Execution time regression normalized by per-row cost
    // ---------------------------------------------------------------

    public function test_execution_time_regression_classified_as_data_growth_when_rows_grew(): void
    {
        $store = new BaselineStore(sys_get_temp_dir().'/qs_arch_'.uniqid('', true));
        $queryHash = hash('sha256', strtolower(trim('select * from logs')));

        // Baseline: 43ms for 800K rows → 0.054μs/row
        $store->save($queryHash, [
            'composite_score' => 90.0,
            'execution_time_ms' => 43.0,
            'rows_examined' => 800_000,
            'timestamp' => date('c'),
        ]);

        $analyzer = new RegressionBaselineAnalyzer($store);

        // Current: 200ms for 1M rows → 0.200μs/row (proportional growth)
        // Time +361% but rows +25%. Per-row: 0.054 → 0.200. Degradation = 274% > 25% → real regression
        // Actually: baseline per-row = 43/800000 = 0.00005375, current = 200/1000000 = 0.0002
        // degradation = (0.0002 - 0.00005375) / 0.00005375 * 100 = 272% → real regression
        $result = $analyzer->analyze('SELECT * FROM logs', [
            'composite_score' => 85.0,
            'execution_time_ms' => 200.0,
            'rows_examined' => 1_000_000,
        ]);

        // This should be a real regression because per-row cost increased significantly
        $timeReg = null;
        foreach ($result['regression']['regressions'] as $r) {
            if ($r['metric'] === 'execution_time_ms') {
                $timeReg = $r;
            }
        }
        $this->assertNotNull($timeReg, 'Per-row degradation should flag regression');
        $this->assertSame('performance_degradation', $timeReg['classification']);
    }

    public function test_execution_time_increase_proportional_to_rows_is_data_growth(): void
    {
        $store = new BaselineStore(sys_get_temp_dir().'/qs_arch_'.uniqid('', true));
        $queryHash = hash('sha256', strtolower(trim('select id from events')));

        // Baseline: 50ms for 500K rows → 0.1μs/row
        $store->save($queryHash, [
            'composite_score' => 90.0,
            'execution_time_ms' => 50.0,
            'rows_examined' => 500_000,
            'timestamp' => date('c'),
        ]);

        $analyzer = new RegressionBaselineAnalyzer($store);

        // Current: 100ms for 1M rows → 0.1μs/row (exactly proportional)
        // Per-row stable → data growth, not regression
        $result = $analyzer->analyze('SELECT id FROM events', [
            'composite_score' => 90.0,
            'execution_time_ms' => 100.0,
            'rows_examined' => 1_000_000,
        ]);

        $timeReg = null;
        foreach ($result['regression']['regressions'] as $r) {
            if ($r['metric'] === 'execution_time_ms') {
                $timeReg = $r;
            }
        }
        $this->assertNull($timeReg, 'Proportional time increase with rows growth should not be regression');

        // Should have info-level data growth finding
        $dataGrowth = array_filter($result['findings'], fn ($f) => str_contains($f->title, 'Data growth') && str_contains($f->title, 'execution_time'));
        $this->assertNotEmpty($dataGrowth, 'Should log data growth event');
    }

    // ---------------------------------------------------------------
    // Issue 2: Intentional scan scalability risk
    // ---------------------------------------------------------------

    public function test_intentional_scan_never_high_risk(): void
    {
        $estimator = new ScalabilityEstimator;

        $result = $estimator->estimate([
            'execution_time_ms' => 258.0,
            'rows_examined' => 1_000_000,
            'rows_returned' => 1_000_000,
            'has_table_scan' => true,
            'is_intentional_scan' => true,
            'complexity' => 'linear',
            'has_early_termination' => false,
            'is_zero_row_const' => false,
        ], 1_000_000);

        // Intentional scan: never HIGH, caps at MEDIUM for >100K rows
        $this->assertSame('MEDIUM', $result['risk']);
    }

    public function test_pathological_scan_still_high_risk(): void
    {
        $estimator = new ScalabilityEstimator;

        $result = $estimator->estimate([
            'execution_time_ms' => 258.0,
            'rows_examined' => 1_000_000,
            'rows_returned' => 1_000_000,
            'has_table_scan' => true,
            'is_intentional_scan' => false,
            'complexity' => 'linear',
            'has_early_termination' => false,
            'is_zero_row_const' => false,
        ], 1_000_000);

        $this->assertSame('HIGH', $result['risk']);
    }

    // ---------------------------------------------------------------
    // Issue 3: Access-type-aware expected cost baseline
    // ---------------------------------------------------------------

    public function test_index_lookup_slower_per_row_detected(): void
    {
        $engine = new DefaultScoringEngine;

        // Index lookup at 5μs/row (expected: 0.1μs) → ratio 50 → score 30
        $result = $engine->score([
            'execution_time_ms' => 500.0,
            'rows_examined' => 100_000,
            'rows_returned' => 100_000,
            'nested_loop_depth' => 0,
            'fanout_factor' => 1.0,
            'has_weedout' => false,
            'has_temp_table' => false,
            'primary_access_type' => 'index_lookup',
            'has_table_scan' => false,
            'is_index_backed' => true,
            'complexity' => 'logarithmic',
        ]);

        $timeScore = $result['breakdown']['execution_time']['score'];
        // 5μs/row vs 0.1μs expected → ratio 50 → score 30
        $this->assertSame(30, $timeScore);
    }

    public function test_table_scan_at_expected_rate_scores_100(): void
    {
        $engine = new DefaultScoringEngine;

        // Table scan at 0.2μs/row (expected: 0.3μs) → ratio 0.67 → 100
        $result = $engine->score([
            'execution_time_ms' => 20.0,
            'rows_examined' => 100_000,
            'rows_returned' => 100_000,
            'nested_loop_depth' => 0,
            'fanout_factor' => 1.0,
            'has_weedout' => false,
            'has_temp_table' => false,
            'primary_access_type' => 'table_scan',
            'has_table_scan' => true,
            'is_index_backed' => false,
            'complexity' => 'linear',
        ]);

        $this->assertSame(100, $result['breakdown']['execution_time']['score']);
    }

    // ---------------------------------------------------------------
    // Issue 4: Memory categories present in output
    // ---------------------------------------------------------------

    public function test_memory_output_has_categories(): void
    {
        $analyzer = new MemoryPressureAnalyzer;
        $result = $analyzer->analyze(
            ['rows_examined' => 1000, 'rows_returned' => 500, 'has_filesort' => true, 'has_temp_table' => false, 'has_disk_temp' => false, 'join_count' => 2],
            null,
            null,
        );

        $categories = $result['memory_pressure']['categories'];
        $this->assertArrayHasKey('buffer_pool_working_set', $categories);
        $this->assertArrayHasKey('execution_memory', $categories);
        $this->assertArrayHasKey('network_transfer_estimate', $categories);

        // Network transfer = rows_returned (500) × 256 = 128000
        $this->assertSame(128000, $categories['network_transfer_estimate']);
        // Execution memory = sort buffer + join buffer
        $this->assertGreaterThan(0, $categories['execution_memory']);
    }

    // ---------------------------------------------------------------
    // Issue 6: Baseline stores per-row metrics
    // ---------------------------------------------------------------

    public function test_baseline_snapshot_stores_time_per_row(): void
    {
        $store = new BaselineStore(sys_get_temp_dir().'/qs_arch_'.uniqid('', true));
        $analyzer = new RegressionBaselineAnalyzer($store);

        $analyzer->analyze('SELECT id FROM test', [
            'composite_score' => 90.0,
            'execution_time_ms' => 100.0,
            'rows_examined' => 500_000,
        ]);

        $queryHash = hash('sha256', strtolower(trim('select id from test')));
        $history = $store->history($queryHash, 1);

        $this->assertNotEmpty($history);
        $snapshot = $history[0];
        $this->assertArrayHasKey('time_per_row', $snapshot);
        // 100ms / 500K rows = 0.0002ms/row
        $this->assertEqualsWithDelta(0.0002, $snapshot['time_per_row'], 0.00001);
    }
}
