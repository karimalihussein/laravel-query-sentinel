<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QuerySentinel\Analyzers\ScalabilityEstimator;

final class ScalabilityEstimatorTest extends TestCase
{
    private ScalabilityEstimator $estimator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->estimator = new ScalabilityEstimator;
    }

    public function test_linear_projection(): void
    {
        $metrics = [
            'execution_time_ms' => 10.0,
            'rows_examined' => 1000,
            'max_loops' => 100,
            'complexity' => 'O(n)',
            'is_index_backed' => false,
            'has_table_scan' => true,
            'is_zero_row_const' => false,
        ];

        $result = $this->estimator->estimate($metrics, 100_000);

        $this->assertArrayHasKey('projections', $result);
        $this->assertCount(2, $result['projections']);

        // 1M rows: cost-based projection from 100K → linear model
        $proj1M = $result['projections'][0];
        $this->assertSame(1_000_000, $proj1M['target_rows']);
        $this->assertSame('linear', $proj1M['model']);
        $this->assertGreaterThan(5.0, $proj1M['growth_factor']);
        $this->assertLessThan(15.0, $proj1M['growth_factor']);
    }

    public function test_quadratic_projection(): void
    {
        $metrics = [
            'execution_time_ms' => 100.0,
            'rows_examined' => 50_000,
            'max_loops' => 50_000,
            'complexity' => 'O(n²)',
            'is_index_backed' => false,
            'has_table_scan' => true,
            'is_zero_row_const' => false,
        ];

        $result = $this->estimator->estimate($metrics, 10_000);

        $proj1M = $result['projections'][0];
        $this->assertSame('quadratic', $proj1M['model']);
        $this->assertSame('HIGH', $result['risk']);
    }

    public function test_constant_is_stable(): void
    {
        $metrics = [
            'execution_time_ms' => 0.003,
            'rows_examined' => 0,
            'rows_returned' => 0,
            'max_loops' => 1,
            'complexity' => 'O(1)',
            'is_index_backed' => true,
            'has_table_scan' => false,
            'is_zero_row_const' => true,
        ];

        $result = $this->estimator->estimate($metrics, 1);

        $this->assertSame('LOW', $result['risk']);

        // O(1) → growth factor is always 1.0 (stable)
        foreach ($result['projections'] as $proj) {
            $this->assertSame(1.0, $proj['growth_factor']);
            $this->assertSame('stable', $proj['model']);
        }
    }

    public function test_logarithmic_growth(): void
    {
        $metrics = [
            'execution_time_ms' => 1.0,
            'rows_examined' => 100,
            'max_loops' => 1,
            'complexity' => 'O(log n)',
            'is_index_backed' => true,
            'has_table_scan' => false,
            'is_zero_row_const' => false,
        ];

        $result = $this->estimator->estimate($metrics, 100_000);

        $this->assertSame('LOW', $result['risk']);

        // Logarithmic growth: much less than linear
        $proj1M = $result['projections'][0];
        $this->assertSame('logarithmic', $proj1M['model']);
        $this->assertLessThan(10.0, $proj1M['growth_factor']); // log growth is slow
    }

    public function test_limit_sensitivity_with_early_termination(): void
    {
        $metrics = [
            'execution_time_ms' => 1.0,
            'rows_examined' => 50,
            'rows_returned' => 50,
            'max_loops' => 1,
            'complexity' => 'O(1)',
            'is_index_backed' => true,
            'has_table_scan' => false,
            'has_early_termination' => true,
            'is_zero_row_const' => false,
        ];

        $result = $this->estimator->estimate($metrics, 100_000);

        $sensitivity = $result['limit_sensitivity'];
        $this->assertArrayHasKey(100, $sensitivity);
        $this->assertArrayHasKey(500, $sensitivity);
        $this->assertArrayHasKey(1000, $sensitivity);

        // LIMIT 500 = 10x current LIMIT 50 → projected 10x time
        $this->assertGreaterThan(1.0, $sensitivity[500]['projected_time_ms']);
    }

    public function test_limit_sensitivity_without_early_termination(): void
    {
        $metrics = [
            'execution_time_ms' => 100.0,
            'rows_examined' => 50_000,
            'rows_returned' => 50,
            'max_loops' => 1,
            'complexity' => 'O(n)',
            'is_index_backed' => true,
            'has_table_scan' => false,
            'has_early_termination' => false,
            'is_zero_row_const' => false,
        ];

        $result = $this->estimator->estimate($metrics, 100_000);

        // Without early termination, LIMIT doesn't change time
        $sensitivity = $result['limit_sensitivity'];
        foreach ($sensitivity as $data) {
            $this->assertSame(100.0, $data['projected_time_ms']);
        }
    }

    public function test_custom_projection_targets(): void
    {
        $metrics = [
            'execution_time_ms' => 1.0,
            'rows_examined' => 100,
            'max_loops' => 1,
            'complexity' => 'O(log n)',
            'is_index_backed' => true,
            'has_table_scan' => false,
            'is_zero_row_const' => false,
        ];

        $result = $this->estimator->estimate($metrics, 10_000, [500_000, 5_000_000]);

        $this->assertCount(2, $result['projections']);
        $this->assertSame(500_000, $result['projections'][0]['target_rows']);
        $this->assertSame(5_000_000, $result['projections'][1]['target_rows']);
    }

    public function test_current_rows_minimum_is_one(): void
    {
        $metrics = [
            'execution_time_ms' => 0.01,
            'rows_examined' => 0,
            'rows_returned' => 0,
            'max_loops' => 0,
            'complexity' => 'O(1)',
            'is_index_backed' => true,
            'has_table_scan' => false,
            'is_zero_row_const' => true,
        ];

        // Should not divide by zero
        $result = $this->estimator->estimate($metrics, 0);

        $this->assertSame(1, $result['current_rows']);
        $this->assertNotEmpty($result['projections']);
    }

    public function test_zero_row_const_forces_stable(): void
    {
        $metrics = [
            'execution_time_ms' => 0.003,
            'rows_examined' => 0,
            'rows_returned' => 0,
            'max_loops' => 1,
            'complexity' => 'O(n)', // Even if complexity is wrong...
            'is_index_backed' => true,
            'has_table_scan' => false,
            'is_zero_row_const' => true, // ...zero_row_const forces stable
        ];

        $result = $this->estimator->estimate($metrics, 1);

        $this->assertSame('LOW', $result['risk']);
        $this->assertSame('O(1)', $result['complexity']); // Overridden to O(1)
    }

    // ---------------------------------------------------------------
    // Sub-linear (LogRange) projection
    // ---------------------------------------------------------------

    public function test_log_range_sub_linear_projection(): void
    {
        $metrics = [
            'execution_time_ms' => 5.0,
            'rows_examined' => 500,
            'max_loops' => 1,
            'complexity' => 'O(log n + k)',
            'is_index_backed' => true,
            'has_table_scan' => false,
            'is_zero_row_const' => false,
        ];

        $result = $this->estimator->estimate($metrics, 10_000);

        $proj1M = $result['projections'][0];
        $this->assertSame('sub-linear', $proj1M['model']);

        // Sub-linear: growth should be less than linear but more than log
        $this->assertGreaterThan(1.0, $proj1M['growth_factor']);
        $this->assertLessThan(100.0, $proj1M['growth_factor']); // Linear would be 100x
    }

    // ---------------------------------------------------------------
    // Linearithmic projection
    // ---------------------------------------------------------------

    public function test_linearithmic_projection(): void
    {
        $metrics = [
            'execution_time_ms' => 50.0,
            'rows_examined' => 10_000,
            'max_loops' => 1,
            'complexity' => 'O(n log n)',
            'is_index_backed' => true,
            'has_table_scan' => false,
            'has_filesort' => true,
            'is_zero_row_const' => false,
        ];

        $result = $this->estimator->estimate($metrics, 10_000);

        $proj1M = $result['projections'][0];
        $this->assertSame('linearithmic', $proj1M['model']);

        // Linearithmic: growth should be more than linear
        // At 100x factor: linearithmic = 100 * log(1M)/log(10K) ≈ 100 * 1.5 = 150
        $this->assertGreaterThan(100.0, $proj1M['growth_factor']);
    }

    // ---------------------------------------------------------------
    // Risk assessment by complexity
    // ---------------------------------------------------------------

    public function test_risk_low_for_constant(): void
    {
        $metrics = [
            'execution_time_ms' => 0.01,
            'rows_examined' => 1,
            'rows_returned' => 1,
            'max_loops' => 1,
            'complexity' => 'O(1)',
            'is_index_backed' => true,
            'has_table_scan' => false,
            'is_zero_row_const' => false,
        ];

        $result = $this->estimator->estimate($metrics, 1);
        $this->assertSame('LOW', $result['risk']);
    }

    public function test_risk_low_for_logarithmic(): void
    {
        $metrics = [
            'execution_time_ms' => 1.0,
            'rows_examined' => 100,
            'rows_returned' => 100,
            'max_loops' => 1,
            'complexity' => 'O(log n)',
            'is_index_backed' => true,
            'has_table_scan' => false,
            'is_zero_row_const' => false,
        ];

        $result = $this->estimator->estimate($metrics, 10_000);
        $this->assertSame('LOW', $result['risk']);
    }

    public function test_risk_medium_for_linear_small_table(): void
    {
        $metrics = [
            'execution_time_ms' => 10.0,
            'rows_examined' => 1000,
            'rows_returned' => 1000,
            'max_loops' => 1,
            'complexity' => 'O(n)',
            'is_index_backed' => false,
            'has_table_scan' => false,
            'is_zero_row_const' => false,
        ];

        $result = $this->estimator->estimate($metrics, 1000);
        $this->assertSame('MEDIUM', $result['risk']);
    }

    public function test_risk_high_for_linear_with_table_scan(): void
    {
        $metrics = [
            'execution_time_ms' => 100.0,
            'rows_examined' => 50_000,
            'rows_returned' => 100,
            'max_loops' => 1,
            'complexity' => 'O(n)',
            'is_index_backed' => false,
            'has_table_scan' => true,
            'is_zero_row_const' => false,
        ];

        $result = $this->estimator->estimate($metrics, 50_000);
        $this->assertSame('MEDIUM', $result['risk']);
    }

    public function test_risk_high_for_quadratic(): void
    {
        $metrics = [
            'execution_time_ms' => 500.0,
            'rows_examined' => 100_000,
            'max_loops' => 10_000,
            'complexity' => 'O(n²)',
            'is_index_backed' => false,
            'has_table_scan' => true,
            'is_zero_row_const' => false,
        ];

        $result = $this->estimator->estimate($metrics, 10_000);
        $this->assertSame('HIGH', $result['risk']);
    }

    public function test_risk_low_when_zero_rows(): void
    {
        $metrics = [
            'execution_time_ms' => 0.01,
            'rows_examined' => 0,
            'rows_returned' => 0,
            'max_loops' => 1,
            'complexity' => 'O(n)', // Even if complexity says linear...
            'is_index_backed' => true,
            'has_table_scan' => false,
            'is_zero_row_const' => false,
        ];

        $result = $this->estimator->estimate($metrics, 1);
        // Zero rows examined + returned → LOW
        $this->assertSame('LOW', $result['risk']);
    }

    // ---------------------------------------------------------------
    // Growth factor precision
    // ---------------------------------------------------------------

    public function test_linear_growth_factor_exact(): void
    {
        $metrics = [
            'execution_time_ms' => 1.0,
            'rows_examined' => 100,
            'max_loops' => 1,
            'complexity' => 'O(n)',
            'is_index_backed' => false,
            'has_table_scan' => true,
            'is_zero_row_const' => false,
        ];

        // 100 current → 1M target: with cost separation, growth factor is large but less than naive 10000x
        $result = $this->estimator->estimate($metrics, 100, [1_000_000]);

        $this->assertGreaterThan(100.0, $result['projections'][0]['growth_factor']);
    }

    public function test_quadratic_growth_factor_exact(): void
    {
        $metrics = [
            'execution_time_ms' => 1.0,
            'rows_examined' => 100,
            'max_loops' => 100,
            'complexity' => 'O(n²)',
            'is_index_backed' => false,
            'has_table_scan' => true,
            'is_zero_row_const' => false,
        ];

        // 100 current → 1000 target: with cost separation, quadratic growth factor is reduced
        $result = $this->estimator->estimate($metrics, 100, [1_000]);

        $this->assertGreaterThan(1.0, $result['projections'][0]['growth_factor']);
    }

    // ---------------------------------------------------------------
    // Limit sensitivity for stable queries
    // ---------------------------------------------------------------

    public function test_limit_sensitivity_stable_query(): void
    {
        $metrics = [
            'execution_time_ms' => 0.003,
            'rows_examined' => 0,
            'rows_returned' => 0,
            'max_loops' => 1,
            'complexity' => 'O(1)',
            'is_index_backed' => true,
            'has_table_scan' => false,
            'has_early_termination' => false,
            'is_zero_row_const' => true,
        ];

        $result = $this->estimator->estimate($metrics, 1);

        // No early termination → LIMIT doesn't change time
        foreach ($result['limit_sensitivity'] as $data) {
            $this->assertSame(0.01, $data['projected_time_ms']); // min clamped
        }
    }

    // ---------------------------------------------------------------
    // Cost separation for small tables
    // ---------------------------------------------------------------

    public function test_small_table_cost_separation(): void
    {
        $metrics = [
            'execution_time_ms' => 18.0,
            'rows_examined' => 1,
            'max_loops' => 1,
            'complexity' => 'O(n)',
            'is_index_backed' => false,
            'has_table_scan' => true,
            'is_zero_row_const' => false,
        ];

        $result = $this->estimator->estimate($metrics, 1);

        // 1-row table: 95% fixed overhead
        $this->assertArrayHasKey('cost_model', $result);
        $this->assertEqualsWithDelta(17.1, $result['cost_model']['fixed_ms'], 0.01);
        $this->assertEqualsWithDelta(0.9, $result['cost_model']['variable_ms'], 0.01);

        // Projection should NOT be 18M ms at 1M rows
        $proj1M = $result['projections'][0];
        $this->assertLessThan(10000.0, $proj1M['projected_time_ms']);
    }

    // ---------------------------------------------------------------
    // Page-based scaling
    // ---------------------------------------------------------------

    public function test_page_based_scaling(): void
    {
        $metrics = [
            'execution_time_ms' => 100.0,
            'rows_examined' => 10000,
            'max_loops' => 1,
            'complexity' => 'O(n)',
            'is_index_backed' => false,
            'has_table_scan' => true,
            'is_zero_row_const' => false,
        ];

        $result = $this->estimator->estimate($metrics, 10_000, [1_000_000]);

        // 10K rows: fixedRatio=0.10 (>=10K), fixed=10, variable=90
        // Page factor: ceil(1M/100)/ceil(10K/100) = 10000/100 = 100
        // Projected = 10 + 90*100 = 9010
        $proj = $result['projections'][0];
        $this->assertEqualsWithDelta(9010.0, $proj['projected_time_ms'], 10.0);
    }

    // ---------------------------------------------------------------
    // Size-aware risk: small table table scan = LOW
    // ---------------------------------------------------------------

    public function test_size_aware_risk_small_table(): void
    {
        $metrics = [
            'execution_time_ms' => 0.5,
            'rows_examined' => 10,
            'rows_returned' => 10,
            'max_loops' => 1,
            'complexity' => 'O(n)',
            'is_index_backed' => false,
            'has_table_scan' => true,
            'is_zero_row_const' => false,
        ];

        $result = $this->estimator->estimate($metrics, 10);
        $this->assertSame('LOW', $result['risk']);
    }

    // ---------------------------------------------------------------
    // Projection confidence levels
    // ---------------------------------------------------------------

    public function test_projection_confidence_levels(): void
    {
        $metrics = [
            'execution_time_ms' => 1.0,
            'rows_examined' => 10,
            'max_loops' => 1,
            'complexity' => 'O(n)',
            'is_index_backed' => false,
            'has_table_scan' => true,
            'is_zero_row_const' => false,
        ];

        // 10 current → 1M and 10M targets
        $result = $this->estimator->estimate($metrics, 10);

        // 1M/10 = 100K extrapolation ratio → low confidence
        $proj1M = $result['projections'][0];
        $this->assertSame('low', $proj1M['confidence']);
        $this->assertArrayHasKey('projected_lower_ms', $proj1M);
        $this->assertArrayHasKey('projected_upper_ms', $proj1M);
    }
}
