<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use QuerySentinel\Analyzers\MemoryPressureAnalyzer;
use QuerySentinel\Analyzers\RegressionBaselineAnalyzer;
use QuerySentinel\Analyzers\ScalabilityEstimator;
use QuerySentinel\Analyzers\WorkloadAnalyzer;
use QuerySentinel\Enums\ComplexityClass;
use QuerySentinel\Enums\Severity;
use QuerySentinel\Scoring\DefaultScoringEngine;
use QuerySentinel\Support\BaselineStore;
use QuerySentinel\Support\EnvironmentContext;
use QuerySentinel\Support\ExecutionProfile;
use QuerySentinel\Support\SqlParser;

/**
 * Tests for all 10 production-grade enhancements.
 */
final class ProductionEnhancementsTest extends TestCase
{
    // ========================================================================
    // Enhancement #1: Score Dampening for Large Datasets
    // ========================================================================

    public function test_1m_rows_intentional_scan_dampened(): void
    {
        $engine = new DefaultScoringEngine;
        $scores = $engine->score([
            'execution_time_ms' => 258.0,
            'rows_examined' => 1_000_000,
            'rows_returned' => 1_000_000,
            'primary_access_type' => 'table_scan',
            'has_table_scan' => true,
            'is_index_backed' => false,
            'is_intentional_scan' => true,
            'complexity' => 'O(n)',
            'nested_loop_depth' => 0,
            'fanout_factor' => 1.0,
        ]);

        // 1M rows: max_allowed = 98 - log10(1M/10K) * 2 = 98 - 4 = 94
        $this->assertLessThanOrEqual(94.0, $scores['composite_score']);
        $this->assertTrue($scores['dataset_dampened']);
    }

    public function test_100k_rows_intentional_scan_dampened_to_96(): void
    {
        $engine = new DefaultScoringEngine;
        $scores = $engine->score([
            'execution_time_ms' => 25.0,
            'rows_examined' => 100_000,
            'rows_returned' => 100_000,
            'primary_access_type' => 'table_scan',
            'has_table_scan' => true,
            'is_index_backed' => false,
            'is_intentional_scan' => true,
            'complexity' => 'O(n)',
            'nested_loop_depth' => 0,
            'fanout_factor' => 1.0,
        ]);

        // 100K rows: max_allowed = 98 - log10(100K/10K) * 2 = 98 - 2 = 96
        $this->assertLessThanOrEqual(96.0, $scores['composite_score']);
        $this->assertTrue($scores['dataset_dampened']);
    }

    public function test_small_intentional_scan_not_dampened(): void
    {
        $engine = new DefaultScoringEngine;
        $scores = $engine->score([
            'execution_time_ms' => 0.5,
            'rows_examined' => 100,
            'rows_returned' => 100,
            'primary_access_type' => 'table_scan',
            'has_table_scan' => true,
            'is_index_backed' => false,
            'is_intentional_scan' => true,
            'complexity' => 'O(n)',
            'nested_loop_depth' => 0,
            'fanout_factor' => 1.0,
        ]);

        // 100 rows is under 10K — no dampening applied
        $this->assertFalse($scores['dataset_dampened']);
    }

    public function test_context_override_caps_at_95_for_intentional(): void
    {
        $engine = new DefaultScoringEngine;

        // Use execution time of 50ms: gives execution_time_score ~81 (absolute for <1K rows)
        // This makes natural composite < 95, so context override fires
        $scores = $engine->score([
            'execution_time_ms' => 50.0,
            'rows_examined' => 500,
            'rows_returned' => 500,
            'primary_access_type' => 'table_scan',
            'has_table_scan' => true,
            'is_index_backed' => false,
            'is_intentional_scan' => true,
            'complexity' => 'O(n)',
            'nested_loop_depth' => 0,
        ]);

        // Intentional scan override fires and caps at 95, not 98
        $this->assertTrue($scores['context_override']);
        $this->assertGreaterThanOrEqual(95.0, $scores['composite_score']);
        // Must not exceed 95 for intentional scans (was 98 before fix)
        $this->assertLessThanOrEqual(95.0, $scores['composite_score']);
    }

    public function test_non_intentional_scan_not_dampened(): void
    {
        $engine = new DefaultScoringEngine;
        $scores = $engine->score([
            'execution_time_ms' => 0.5,
            'rows_examined' => 1_000_000,
            'rows_returned' => 1_000_000,
            'primary_access_type' => 'const_row',
            'is_index_backed' => true,
            'has_covering_index' => true,
            'is_intentional_scan' => false,
            'complexity' => 'O(1)',
            'nested_loop_depth' => 0,
        ]);

        // Non-intentional scan: dampening doesn't apply
        $this->assertFalse($scores['dataset_dampened']);
    }

    // ========================================================================
    // Enhancement #2: LIMIT Sensitivity Model
    // ========================================================================

    public function test_limit_reduces_time_for_intentional_scan_no_order_by(): void
    {
        $estimator = new ScalabilityEstimator;
        $result = $estimator->estimate([
            'execution_time_ms' => 258.0,
            'rows_examined' => 1_000_000,
            'rows_returned' => 1_000_000,
            'complexity' => 'O(n)',
            'is_intentional_scan' => true,
            'has_filesort' => false,
            'has_early_termination' => false,
        ], 1_000_000, [1_000_000], 'SELECT id, name FROM users');

        $limitSensitivity = $result['limit_sensitivity'];

        // LIMIT 100 on 1M rows: factor = min(1.0, 100/1M) = 0.0001, clamped to 0.01 → 2.58ms
        // Key assertion: dramatically less than full scan (258ms)
        $this->assertLessThan(10.0, $limitSensitivity[100]['projected_time_ms']);
        $this->assertStringContainsString('Sequential scan stops early', $limitSensitivity[100]['note']);
    }

    public function test_limit_no_reduction_with_order_by(): void
    {
        $estimator = new ScalabilityEstimator;
        $result = $estimator->estimate([
            'execution_time_ms' => 258.0,
            'rows_examined' => 1_000_000,
            'rows_returned' => 1_000_000,
            'complexity' => 'O(n)',
            'is_intentional_scan' => false,
            'has_filesort' => true,
            'has_early_termination' => false,
        ], 1_000_000, [1_000_000], 'SELECT id, name FROM users ORDER BY name');

        $limitSensitivity = $result['limit_sensitivity'];

        // ORDER BY present: LIMIT doesn't reduce work
        $this->assertEqualsWithDelta(258.0, $limitSensitivity[100]['projected_time_ms'], 0.01);
        $this->assertStringContainsString('ORDER BY requires full scan + sort', $limitSensitivity[100]['note']);
    }

    public function test_limit_existing_early_termination_preserved(): void
    {
        $estimator = new ScalabilityEstimator;
        $result = $estimator->estimate([
            'execution_time_ms' => 5.0,
            'rows_examined' => 100,
            'rows_returned' => 100,
            'complexity' => 'O(n)',
            'has_early_termination' => true,
        ], 100, [1_000_000]);

        // Existing early termination logic still works
        $this->assertNotEmpty($result['limit_sensitivity']);
    }

    // ========================================================================
    // Enhancement #3: Dynamic Buffer Pool Recommendation
    // ========================================================================

    public function test_proportional_buffer_pool_recommendation(): void
    {
        $analyzer = new MemoryPressureAnalyzer;
        $env = new EnvironmentContext('8.0.0', 134217728, 10000, 16384, 16777216, 16777216, 1.0, false, 'test');
        $profile = new ExecutionProfile(
            nestedLoopDepth: 0,
            joinFanouts: [],
            btreeDepths: [],
            logicalReads: 0,
            physicalReads: 10000,
            scanComplexity: ComplexityClass::Linear,
            sortComplexity: ComplexityClass::Constant,
        );

        $result = $analyzer->analyze(
            ['rows_examined' => 1_000_000, 'rows_returned' => 1_000_000],
            $env,
            $profile,
        );

        $recs = $result['memory_pressure']['recommendations'];
        $bufferPoolRec = array_filter($recs, fn ($r) => str_contains($r, 'innodb_buffer_pool_size'));

        // Should have a proportional recommendation with GB value
        $this->assertNotEmpty($bufferPoolRec);
        $recText = implode(' ', $bufferPoolRec);
        $this->assertMatchesRegularExpression('/\d+ GB/', $recText);
    }

    public function test_no_buffer_pool_recommendation_below_30_pct(): void
    {
        $analyzer = new MemoryPressureAnalyzer;
        // 128MB pool, small working set
        $env = new EnvironmentContext('8.0.0', 134217728, 10000, 16384, 16777216, 16777216, 1.0, false, 'test');

        $result = $analyzer->analyze(
            ['rows_examined' => 100, 'rows_returned' => 100],
            $env,
            null,
        );

        $recs = $result['memory_pressure']['recommendations'];
        $bufferPoolRec = array_filter($recs, fn ($r) => str_contains($r, 'innodb_buffer_pool_size'));

        // Small working set: no recommendation
        $this->assertEmpty($bufferPoolRec);
    }

    // ========================================================================
    // Enhancement #4: Memory Concurrency Model
    // ========================================================================

    public function test_concurrency_multiplies_execution_memory(): void
    {
        $analyzer = new MemoryPressureAnalyzer(concurrentSessions: 10);
        $result = $analyzer->analyze(
            ['rows_examined' => 1000, 'rows_returned' => 1000, 'has_filesort' => true],
            null,
            null,
        );

        $concurrency = $result['memory_pressure']['concurrency_adjusted'];
        $this->assertSame(10, $concurrency['concurrent_sessions']);
        $this->assertSame(
            $concurrency['execution_memory_per_session'] * 10,
            $concurrency['concurrent_execution_memory']
        );
    }

    public function test_buffer_pool_not_multiplied_by_concurrency(): void
    {
        $analyzer = new MemoryPressureAnalyzer(concurrentSessions: 10);
        $result = $analyzer->analyze(
            ['rows_examined' => 1000, 'rows_returned' => 1000],
            null,
            null,
        );

        $categories = $result['memory_pressure']['categories'];
        $concurrency = $result['memory_pressure']['concurrency_adjusted'];

        // Buffer pool is shared, not in concurrent_total
        // concurrent_total = concurrent_execution + buffer_pool (not multiplied)
        $this->assertSame(
            $concurrency['concurrent_execution_memory'] + $categories['buffer_pool_working_set'],
            $concurrency['concurrent_total_estimated']
        );
    }

    public function test_default_concurrency_1_backward_compatible(): void
    {
        $analyzer = new MemoryPressureAnalyzer;
        $result = $analyzer->analyze(
            ['rows_examined' => 1000, 'rows_returned' => 1000],
            null,
            null,
        );

        $concurrency = $result['memory_pressure']['concurrency_adjusted'];
        $this->assertSame(1, $concurrency['concurrent_sessions']);
        $this->assertSame(
            $concurrency['execution_memory_per_session'],
            $concurrency['concurrent_execution_memory']
        );
    }

    public function test_high_risk_with_concurrent_sessions(): void
    {
        // With concurrency=10, moderate single-query memory becomes high under load
        $analyzerSingle = new MemoryPressureAnalyzer(concurrentSessions: 1);
        $analyzerConcurrent = new MemoryPressureAnalyzer(concurrentSessions: 50);

        $metrics = ['rows_examined' => 100_000, 'rows_returned' => 100_000, 'has_filesort' => true, 'has_temp_table' => true];

        $singleResult = $analyzerSingle->analyze($metrics, null, null);
        $concurrentResult = $analyzerConcurrent->analyze($metrics, null, null);

        // Concurrent result should have equal or higher risk
        $riskOrder = ['low' => 0, 'moderate' => 1, 'high' => 2];
        $singleRisk = $riskOrder[$singleResult['memory_pressure']['memory_risk']] ?? 0;
        $concurrentRisk = $riskOrder[$concurrentResult['memory_pressure']['memory_risk']] ?? 0;

        $this->assertGreaterThanOrEqual($singleRisk, $concurrentRisk);
    }

    // ========================================================================
    // Enhancement #5: Regression Engine Extended Snapshot
    // ========================================================================

    public function test_snapshot_has_table_size_and_cache_state(): void
    {
        $storePath = sys_get_temp_dir().'/qs_prod_'.uniqid('', true);
        $store = new BaselineStore($storePath);
        $analyzer = new RegressionBaselineAnalyzer($store);

        $analyzer->analyze('SELECT id FROM test_table', [
            'composite_score' => 90.0,
            'grade' => 'A',
            'execution_time_ms' => 10.0,
            'rows_examined' => 5000,
            'rows_returned' => 5000,
            'complexity' => 'O(n)',
            'primary_access_type' => 'table_scan',
            'indexes_used' => [],
            'finding_counts' => [],
            'buffer_pool_utilization' => 0.85,
            'is_cold_cache' => false,
        ]);

        // Read back the snapshot
        $hash = hash('sha256', strtolower(trim('select id from test_table')));
        $snapshot = $store->load($hash);

        $this->assertNotNull($snapshot);
        $this->assertSame(5000, $snapshot['table_size']);
        $this->assertEqualsWithDelta(0.85, $snapshot['buffer_pool_utilization'], 0.001);
        $this->assertFalse($snapshot['is_cold_cache']);
    }

    public function test_cold_to_warm_suppresses_time_improvement(): void
    {
        $storePath = sys_get_temp_dir().'/qs_cold_'.uniqid('', true);
        $store = new BaselineStore($storePath);
        $analyzer = new RegressionBaselineAnalyzer($store);

        // Save 3 cold-cache baselines with slow time
        for ($i = 0; $i < 3; $i++) {
            $analyzer->analyze('SELECT id FROM cold_test', [
                'composite_score' => 70.0,
                'grade' => 'B',
                'execution_time_ms' => 500.0,
                'rows_examined' => 10000,
                'rows_returned' => 10000,
                'complexity' => 'O(n)',
                'primary_access_type' => 'table_scan',
                'indexes_used' => [],
                'finding_counts' => [],
                'is_cold_cache' => true,
            ]);
        }

        // Now run with warm cache and faster time
        $result = $analyzer->analyze('SELECT id FROM cold_test', [
            'composite_score' => 90.0,
            'grade' => 'A',
            'execution_time_ms' => 50.0,
            'rows_examined' => 10000,
            'rows_returned' => 10000,
            'complexity' => 'O(n)',
            'primary_access_type' => 'table_scan',
            'indexes_used' => [],
            'finding_counts' => [],
            'is_cold_cache' => false,
        ]);

        // Time "improvement" from cold→warm should be suppressed (not flagged as genuine improvement)
        $timeImprovements = array_filter(
            $result['regression']['improvements'] ?? [],
            fn ($imp) => ($imp['metric'] ?? '') === 'execution_time_ms'
        );

        $this->assertEmpty($timeImprovements);
    }

    // ========================================================================
    // Enhancement #6: Dynamic Scalability Classification
    // ========================================================================

    public function test_export_linear_classification(): void
    {
        $estimator = new ScalabilityEstimator;
        $result = $estimator->estimate([
            'execution_time_ms' => 258.0,
            'rows_examined' => 1_000_000,
            'rows_returned' => 1_000_000,
            'complexity' => 'O(n)',
            'is_intentional_scan' => true,
        ], 1_000_000, [1_000_000], 'SELECT id, name FROM users');

        $this->assertSame('EXPORT_LINEAR', $result['linear_subtype']);
    }

    public function test_analytical_linear_classification(): void
    {
        $estimator = new ScalabilityEstimator;
        $result = $estimator->estimate([
            'execution_time_ms' => 100.0,
            'rows_examined' => 500_000,
            'complexity' => 'O(n)',
            'is_intentional_scan' => false,
            'has_table_scan' => false,
        ], 500_000, [1_000_000], 'SELECT department, COUNT(*) FROM users GROUP BY department');

        $this->assertSame('ANALYTICAL_LINEAR', $result['linear_subtype']);
    }

    public function test_index_missed_linear_classification(): void
    {
        $estimator = new ScalabilityEstimator;
        $result = $estimator->estimate([
            'execution_time_ms' => 300.0,
            'rows_examined' => 1_000_000,
            'complexity' => 'O(n)',
            'is_intentional_scan' => false,
            'has_table_scan' => true,
        ], 1_000_000, [1_000_000], 'SELECT * FROM users WHERE email = \'test@test.com\'');

        $this->assertSame('INDEX_MISSED_LINEAR', $result['linear_subtype']);
    }

    public function test_pathological_linear_classification(): void
    {
        $estimator = new ScalabilityEstimator;
        $result = $estimator->estimate([
            'execution_time_ms' => 300.0,
            'rows_examined' => 1_000_000,
            'complexity' => 'O(n)',
            'is_intentional_scan' => false,
            'has_table_scan' => false,
        ], 1_000_000, [1_000_000], 'SELECT * FROM users WHERE status IN (SELECT status FROM statuses)');

        $this->assertSame('PATHOLOGICAL_LINEAR', $result['linear_subtype']);
    }

    public function test_non_linear_has_no_subtype(): void
    {
        $estimator = new ScalabilityEstimator;
        $result = $estimator->estimate([
            'execution_time_ms' => 0.5,
            'rows_examined' => 1,
            'complexity' => 'O(1)',
        ], 1, [1_000_000]);

        $this->assertNull($result['linear_subtype']);
    }

    // ========================================================================
    // Enhancement #7: Confidence-Gated Scoring
    // ========================================================================

    public function test_confidence_gate_caps_at_75_below_50_pct(): void
    {
        $engine = new DefaultScoringEngine;
        $result = $engine->applyConfidenceGate(95.0, 0.4);

        $this->assertSame(75.0, $result['composite_score']);
        $this->assertTrue($result['confidence_capped']);
    }

    public function test_confidence_gate_caps_at_90_below_70_pct(): void
    {
        $engine = new DefaultScoringEngine;
        $result = $engine->applyConfidenceGate(95.0, 0.6);

        $this->assertSame(90.0, $result['composite_score']);
        $this->assertTrue($result['confidence_capped']);
    }

    public function test_no_confidence_cap_above_70_pct(): void
    {
        $engine = new DefaultScoringEngine;
        $result = $engine->applyConfidenceGate(95.0, 0.8);

        $this->assertSame(95.0, $result['composite_score']);
        $this->assertFalse($result['confidence_capped']);
    }

    // ========================================================================
    // Enhancement #8: Early Termination Detection
    // ========================================================================

    public function test_has_limit_detection(): void
    {
        $this->assertTrue(SqlParser::hasLimit('SELECT id FROM users LIMIT 10'));
        $this->assertTrue(SqlParser::hasLimit('select * from t limit 100'));
        $this->assertFalse(SqlParser::hasLimit('SELECT id FROM users'));
    }

    public function test_has_exists_detection(): void
    {
        $this->assertTrue(SqlParser::hasExists('SELECT 1 WHERE EXISTS (SELECT 1 FROM users)'));
        $this->assertTrue(SqlParser::hasExists('SELECT * FROM t WHERE exists(SELECT 1)'));
        $this->assertFalse(SqlParser::hasExists('SELECT id FROM users'));
    }

    public function test_aggregation_without_group_by(): void
    {
        $this->assertTrue(SqlParser::hasAggregationWithoutGroupBy('SELECT COUNT(*) FROM users'));
        $this->assertTrue(SqlParser::hasAggregationWithoutGroupBy('SELECT MAX(id) FROM users'));
        $this->assertTrue(SqlParser::hasAggregationWithoutGroupBy('SELECT SUM(amount) FROM orders'));
    }

    public function test_aggregation_with_group_by_not_early_termination(): void
    {
        $this->assertFalse(SqlParser::hasAggregationWithoutGroupBy('SELECT COUNT(*) FROM users GROUP BY status'));
        $this->assertFalse(SqlParser::hasAggregationWithoutGroupBy('SELECT department, AVG(salary) FROM emp GROUP BY department'));
    }

    // ========================================================================
    // Enhancement #9: Blended Scoring for Medium Datasets
    // ========================================================================

    public function test_blend_at_1000_rows_is_pure_absolute(): void
    {
        $engine = new DefaultScoringEngine;

        // At exactly 1000 rows, weight = 0.0 (pure absolute)
        $scores = $engine->score([
            'execution_time_ms' => 50.0,
            'rows_examined' => 1000,
            'rows_returned' => 1000,
            'primary_access_type' => 'table_scan',
            'is_index_backed' => false,
            'has_table_scan' => true,
            'complexity' => 'O(n)',
            'nested_loop_depth' => 0,
        ]);

        // 50ms absolute → ~81 score (90 - (50-10) * 20/90 ≈ 81)
        $executionScore = $scores['breakdown']['execution_time']['score'] ?? 0;
        $this->assertEqualsWithDelta(81, $executionScore, 2);
    }

    public function test_blend_at_5500_rows_is_mixed(): void
    {
        $engine = new DefaultScoringEngine;

        // At 5500 rows, weight = (5500-1000)/9000 = 0.5 (50/50 blend)
        $scores = $engine->score([
            'execution_time_ms' => 50.0,
            'rows_examined' => 5500,
            'rows_returned' => 5500,
            'primary_access_type' => 'table_scan',
            'is_index_backed' => false,
            'has_table_scan' => true,
            'complexity' => 'O(n)',
            'nested_loop_depth' => 0,
        ]);

        $executionScore = $scores['breakdown']['execution_time']['score'] ?? 0;

        // Blended: absolute(50ms) ≈ 81, deviation(50ms/5500rows ≈ 9μs/row vs 0.3μs expected = ~30x → 30)
        // Blend: 81 * 0.5 + 30 * 0.5 ≈ 56
        $this->assertGreaterThan(30, $executionScore);
        $this->assertLessThan(90, $executionScore);
    }

    public function test_below_1000_rows_uses_pure_absolute(): void
    {
        $engine = new DefaultScoringEngine;
        $scores = $engine->score([
            'execution_time_ms' => 0.5,
            'rows_examined' => 100,
            'rows_returned' => 100,
            'primary_access_type' => 'table_scan',
            'complexity' => 'O(n)',
            'nested_loop_depth' => 0,
        ]);

        // 0.5ms → 100 score (< 1ms threshold)
        $executionScore = $scores['breakdown']['execution_time']['score'] ?? 0;
        $this->assertSame(100, $executionScore);
    }

    // ========================================================================
    // Enhancement #10: Network Transfer Awareness
    // ========================================================================

    public function test_network_transfer_warning_above_100mb(): void
    {
        $analyzer = new MemoryPressureAnalyzer;
        $result = $analyzer->analyze(
            ['rows_examined' => 500_000, 'rows_returned' => 500_000],
            null,
            null,
        );

        // 500K × 256 = 128MB → should generate Warning (>100MB)
        $networkFindings = array_filter(
            $result['findings'],
            fn ($f) => str_contains($f->title, 'network transfer')
        );

        $this->assertNotEmpty($networkFindings);
        $finding = array_values($networkFindings)[0];
        $this->assertSame(Severity::Warning, $finding->severity);
    }

    public function test_network_transfer_optimization_above_50mb(): void
    {
        $analyzer = new MemoryPressureAnalyzer;
        $result = $analyzer->analyze(
            ['rows_examined' => 250_000, 'rows_returned' => 250_000],
            null,
            null,
        );

        // 250K × 256 = 64MB → should generate Optimization (50-100MB)
        $networkFindings = array_filter(
            $result['findings'],
            fn ($f) => str_contains($f->title, 'network transfer')
        );

        $this->assertNotEmpty($networkFindings);
        $finding = array_values($networkFindings)[0];
        $this->assertSame(Severity::Optimization, $finding->severity);
    }

    public function test_no_network_transfer_warning_below_threshold(): void
    {
        $analyzer = new MemoryPressureAnalyzer;
        $result = $analyzer->analyze(
            ['rows_examined' => 1000, 'rows_returned' => 1000],
            null,
            null,
        );

        // 1000 × 256 = 256KB → no warning
        $networkFindings = array_filter(
            $result['findings'],
            fn ($f) => str_contains($f->title, 'network transfer')
        );

        $this->assertEmpty($networkFindings);
    }

    public function test_network_transfer_recommendation_text(): void
    {
        $analyzer = new MemoryPressureAnalyzer;
        $result = $analyzer->analyze(
            ['rows_examined' => 500_000, 'rows_returned' => 500_000],
            null,
            null,
        );

        $networkFindings = array_filter(
            $result['findings'],
            fn ($f) => str_contains($f->title, 'network transfer')
        );

        $this->assertNotEmpty($networkFindings);
        $finding = array_values($networkFindings)[0];
        $this->assertStringContainsString('cursor()', $finding->recommendation);
        $this->assertStringContainsString('chunk()', $finding->recommendation);
        $this->assertStringContainsString('LIMIT', $finding->recommendation);
    }

    // ========================================================================
    // Enhancement #11: WorkloadAnalyzer — Pattern Detection
    // ========================================================================

    public function test_workload_detects_repeated_full_export(): void
    {
        $storePath = sys_get_temp_dir().'/qs_wl_export_'.uniqid('', true);
        $store = new BaselineStore($storePath);
        $analyzer = new WorkloadAnalyzer($store, frequencyThreshold: 5, exportRowThreshold: 100_000);

        $sql = 'SELECT id, name FROM users';

        // Seed 10 export snapshots
        $hash = hash('sha256', strtolower(trim($sql)));
        for ($i = 0; $i < 10; $i++) {
            $store->save($hash, [
                'composite_score' => 90.0,
                'execution_time_ms' => 258.0,
                'rows_examined' => 500_000,
                'table_size' => 500_000,
                'timestamp' => date('c', time() - 3600 + $i * 60),
            ]);
        }

        $result = $analyzer->analyze($sql, [
            'rows_returned' => 500_000,
            'rows_examined' => 500_000,
        ]);

        $this->assertTrue($result['workload']['is_frequent']);
        $this->assertGreaterThanOrEqual(10, $result['workload']['historical_export_count']);

        $exportPatterns = array_filter(
            $result['workload']['patterns'],
            fn ($p) => $p['type'] === 'REPEATED_FULL_EXPORT'
        );
        $this->assertNotEmpty($exportPatterns);

        // Should generate critical finding
        $criticalFindings = array_filter(
            $result['findings'],
            fn ($f) => $f->severity === Severity::Critical && $f->category === 'workload'
        );
        $this->assertNotEmpty($criticalFindings);
    }

    public function test_workload_detects_high_frequency_large_transfer(): void
    {
        $storePath = sys_get_temp_dir().'/qs_wl_transfer_'.uniqid('', true);
        $store = new BaselineStore($storePath);
        $analyzer = new WorkloadAnalyzer($store, frequencyThreshold: 5, exportRowThreshold: 1_000_000);

        $sql = 'SELECT * FROM orders WHERE status = \'active\'';
        $hash = hash('sha256', strtolower(trim($sql)));

        // Seed snapshots with large transfers but below export threshold
        for ($i = 0; $i < 10; $i++) {
            $store->save($hash, [
                'composite_score' => 80.0,
                'execution_time_ms' => 100.0,
                'rows_examined' => 250_000,
                'table_size' => 250_000, // 250K × 256 = 64MB > 50MB threshold
                'timestamp' => date('c', time() - 3600 + $i * 60),
            ]);
        }

        $result = $analyzer->analyze($sql, [
            'rows_returned' => 250_000,
            'rows_examined' => 250_000,
        ]);

        $transferPatterns = array_filter(
            $result['workload']['patterns'],
            fn ($p) => $p['type'] === 'HIGH_FREQUENCY_LARGE_TRANSFER'
        );
        $this->assertNotEmpty($transferPatterns);
    }

    public function test_workload_detects_burst_pattern(): void
    {
        $storePath = sys_get_temp_dir().'/qs_wl_burst_'.uniqid('', true);
        $store = new BaselineStore($storePath);
        $analyzer = new WorkloadAnalyzer($store, frequencyThreshold: 3);

        $sql = 'SELECT * FROM users WHERE id = 1';
        $hash = hash('sha256', strtolower(trim($sql)));

        // Seed 6 snapshots within 30 seconds (burst)
        $now = time();
        for ($i = 0; $i < 6; $i++) {
            $store->save($hash, [
                'composite_score' => 95.0,
                'execution_time_ms' => 0.5,
                'rows_examined' => 1,
                'table_size' => 1,
                'timestamp' => date('c', $now + $i * 5), // 5 seconds apart
            ]);
        }

        $result = $analyzer->analyze($sql, [
            'rows_returned' => 1,
            'rows_examined' => 1,
        ]);

        $burstPatterns = array_filter(
            $result['workload']['patterns'],
            fn ($p) => $p['type'] === 'API_MISUSE_BURST'
        );
        $this->assertNotEmpty($burstPatterns);
    }

    public function test_workload_no_patterns_for_new_query(): void
    {
        $storePath = sys_get_temp_dir().'/qs_wl_new_'.uniqid('', true);
        $store = new BaselineStore($storePath);
        $analyzer = new WorkloadAnalyzer($store);

        $result = $analyzer->analyze('SELECT 1', [
            'rows_returned' => 1,
            'rows_examined' => 1,
        ]);

        $this->assertSame(0, $result['workload']['query_frequency']);
        $this->assertFalse($result['workload']['is_frequent']);
        $this->assertEmpty($result['workload']['patterns']);
        $this->assertEmpty($result['findings']);
    }

    public function test_workload_high_frequency_info_pattern(): void
    {
        $storePath = sys_get_temp_dir().'/qs_wl_freq_'.uniqid('', true);
        $store = new BaselineStore($storePath);
        $analyzer = new WorkloadAnalyzer($store, frequencyThreshold: 5);

        $sql = 'SELECT id FROM users WHERE id = 1';
        $hash = hash('sha256', strtolower(trim($sql)));

        // Seed 10 small snapshots (not exports, not large transfers)
        for ($i = 0; $i < 10; $i++) {
            $store->save($hash, [
                'composite_score' => 95.0,
                'execution_time_ms' => 0.5,
                'rows_examined' => 1,
                'table_size' => 1,
                'timestamp' => date('c', time() - 86400 + $i * 3600), // 1 hour apart
            ]);
        }

        $result = $analyzer->analyze($sql, [
            'rows_returned' => 1,
            'rows_examined' => 1,
        ]);

        $this->assertTrue($result['workload']['is_frequent']);

        $highFreqPatterns = array_filter(
            $result['workload']['patterns'],
            fn ($p) => $p['type'] === 'HIGH_FREQUENCY'
        );
        $this->assertNotEmpty($highFreqPatterns);

        // No findings for simple high frequency (info-level only in pattern, not finding)
        $workloadFindings = array_filter(
            $result['findings'],
            fn ($f) => $f->category === 'workload'
        );
        $this->assertEmpty($workloadFindings);
    }

    // ========================================================================
    // Enhancement #12: Network Pressure Classification
    // ========================================================================

    public function test_network_pressure_low(): void
    {
        $analyzer = new MemoryPressureAnalyzer;
        $result = $analyzer->analyze(
            ['rows_examined' => 1000, 'rows_returned' => 1000],
            null,
            null,
        );

        $this->assertSame('LOW', $result['memory_pressure']['network_pressure']);
    }

    public function test_network_pressure_moderate(): void
    {
        $analyzer = new MemoryPressureAnalyzer;
        // 250K × 256 = 64MB → MODERATE (>50MB)
        $result = $analyzer->analyze(
            ['rows_examined' => 250_000, 'rows_returned' => 250_000],
            null,
            null,
        );

        $this->assertSame('MODERATE', $result['memory_pressure']['network_pressure']);
    }

    public function test_network_pressure_high(): void
    {
        $analyzer = new MemoryPressureAnalyzer;
        // 500K × 256 = 128MB → HIGH (>100MB)
        $result = $analyzer->analyze(
            ['rows_examined' => 500_000, 'rows_returned' => 500_000],
            null,
            null,
        );

        $this->assertSame('HIGH', $result['memory_pressure']['network_pressure']);
    }

    public function test_network_pressure_critical(): void
    {
        $analyzer = new MemoryPressureAnalyzer;
        // 1M × 256 = 256MB → CRITICAL (>200MB)
        $result = $analyzer->analyze(
            ['rows_examined' => 1_000_000, 'rows_returned' => 1_000_000],
            null,
            null,
        );

        $this->assertSame('CRITICAL', $result['memory_pressure']['network_pressure']);
    }

    // ========================================================================
    // Enhancement #13: PLAN_CHANGE Regression Detection
    // ========================================================================

    public function test_plan_change_detects_access_type_downgrade(): void
    {
        $storePath = sys_get_temp_dir().'/qs_plan_change_'.uniqid('', true);
        $store = new BaselineStore($storePath);
        $analyzer = new RegressionBaselineAnalyzer($store);

        // First run: good access type
        $analyzer->analyze('SELECT id FROM plan_test WHERE id = 1', [
            'composite_score' => 95.0,
            'grade' => 'A',
            'execution_time_ms' => 0.5,
            'rows_examined' => 1,
            'complexity' => 'O(1)',
            'primary_access_type' => 'const_row',
            'indexes_used' => ['PRIMARY'],
            'finding_counts' => [],
        ]);

        // Second run: degraded to table scan
        $result = $analyzer->analyze('SELECT id FROM plan_test WHERE id = 1', [
            'composite_score' => 40.0,
            'grade' => 'D',
            'execution_time_ms' => 100.0,
            'rows_examined' => 100_000,
            'complexity' => 'O(n)',
            'primary_access_type' => 'table_scan',
            'indexes_used' => [],
            'finding_counts' => [],
        ]);

        // Should detect plan_change regression
        $planRegressions = array_filter(
            $result['regression']['regressions'],
            fn ($r) => ($r['metric'] ?? '') === 'plan_change'
        );
        $this->assertNotEmpty($planRegressions);

        $planFinding = array_filter(
            $result['findings'],
            fn ($f) => str_contains($f->title, 'Plan change')
        );
        $this->assertNotEmpty($planFinding);
    }

    public function test_plan_change_detects_access_type_upgrade(): void
    {
        $storePath = sys_get_temp_dir().'/qs_plan_upgrade_'.uniqid('', true);
        $store = new BaselineStore($storePath);
        $analyzer = new RegressionBaselineAnalyzer($store);

        // First run: table scan
        $analyzer->analyze('SELECT id FROM upgrade_test WHERE email = \'x\'', [
            'composite_score' => 40.0,
            'grade' => 'D',
            'execution_time_ms' => 100.0,
            'rows_examined' => 100_000,
            'complexity' => 'O(n)',
            'primary_access_type' => 'table_scan',
            'indexes_used' => [],
            'finding_counts' => [],
        ]);

        // Second run: improved to single row lookup (index was added)
        $result = $analyzer->analyze('SELECT id FROM upgrade_test WHERE email = \'x\'', [
            'composite_score' => 95.0,
            'grade' => 'A',
            'execution_time_ms' => 0.5,
            'rows_examined' => 1,
            'complexity' => 'O(1)',
            'primary_access_type' => 'single_row_lookup',
            'indexes_used' => ['idx_email'],
            'finding_counts' => [],
        ]);

        // Should detect improvement, not regression
        $planImprovements = array_filter(
            $result['regression']['improvements'],
            fn ($imp) => ($imp['metric'] ?? '') === 'plan_change'
        );
        $this->assertNotEmpty($planImprovements);
    }

    public function test_plan_change_no_detection_when_same(): void
    {
        $storePath = sys_get_temp_dir().'/qs_plan_same_'.uniqid('', true);
        $store = new BaselineStore($storePath);
        $analyzer = new RegressionBaselineAnalyzer($store);

        $metrics = [
            'composite_score' => 90.0,
            'grade' => 'A',
            'execution_time_ms' => 5.0,
            'rows_examined' => 100,
            'complexity' => 'O(1)',
            'primary_access_type' => 'single_row_lookup',
            'indexes_used' => ['idx_email'],
            'finding_counts' => [],
        ];

        $analyzer->analyze('SELECT id FROM same_test WHERE email = \'x\'', $metrics);
        $result = $analyzer->analyze('SELECT id FROM same_test WHERE email = \'x\'', $metrics);

        // No plan change regressions or improvements for same access type
        $planRegressions = array_filter(
            $result['regression']['regressions'],
            fn ($r) => ($r['metric'] ?? '') === 'plan_change'
        );
        $planImprovements = array_filter(
            $result['regression']['improvements'],
            fn ($imp) => ($imp['metric'] ?? '') === 'plan_change'
        );

        $this->assertEmpty($planRegressions);
        $this->assertEmpty($planImprovements);
    }
}
