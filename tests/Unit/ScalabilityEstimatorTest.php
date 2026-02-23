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
            'complexity' => 'O(range)',
            'is_index_backed' => true,
            'has_table_scan' => false,
        ];

        $result = $this->estimator->estimate($metrics, 100_000);

        $this->assertArrayHasKey('projections', $result);
        $this->assertCount(2, $result['projections']);

        // 1M rows: 10x factor from 100K
        $proj1M = $result['projections'][0];
        $this->assertSame(1_000_000, $proj1M['target_rows']);
        $this->assertSame('linear', $proj1M['model']);
        $this->assertSame(10.0, $proj1M['growth_factor']);
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
        ];

        $result = $this->estimator->estimate($metrics, 10_000);

        $proj1M = $result['projections'][0];
        $this->assertSame('quadratic', $proj1M['model']);
        $this->assertSame('HIGH', $result['risk']);
    }

    public function test_limit_optimized_is_low_risk(): void
    {
        $metrics = [
            'execution_time_ms' => 0.1,
            'rows_examined' => 50,
            'max_loops' => 1,
            'complexity' => 'O(limit)',
            'is_index_backed' => true,
            'has_table_scan' => false,
        ];

        $result = $this->estimator->estimate($metrics, 100_000);

        $this->assertSame('LOW', $result['risk']);
    }

    public function test_limit_sensitivity_with_early_termination(): void
    {
        $metrics = [
            'execution_time_ms' => 1.0,
            'rows_examined' => 50,
            'rows_returned' => 50,
            'max_loops' => 1,
            'complexity' => 'O(limit)',
            'is_index_backed' => true,
            'has_table_scan' => false,
            'has_early_termination' => true,
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
            'complexity' => 'O(range)',
            'is_index_backed' => true,
            'has_table_scan' => false,
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
            'max_loops' => 0,
            'complexity' => 'O(limit)',
            'is_index_backed' => true,
            'has_table_scan' => false,
        ];

        // Should not divide by zero
        $result = $this->estimator->estimate($metrics, 0);

        $this->assertSame(1, $result['current_rows']);
        $this->assertNotEmpty($result['projections']);
    }
}
