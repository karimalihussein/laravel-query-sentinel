<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QuerySentinel\Scoring\DefaultScoringEngine;

final class ScoringEngineTest extends TestCase
{
    private DefaultScoringEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new DefaultScoringEngine;
    }

    public function test_perfect_query_grades_a_plus(): void
    {
        $metrics = [
            'execution_time_ms' => 0.1,
            'rows_examined' => 50,
            'rows_returned' => 50,
            'nested_loop_depth' => 1,
            'has_table_scan' => false,
            'has_filesort' => false,
            'has_temp_table' => false,
            'has_weedout' => false,
            'has_index_merge' => false,
            'has_covering_index' => true,
            'is_index_backed' => true,
            'has_early_termination' => true,
            'is_zero_row_const' => false,
            'primary_access_type' => 'covering_index_lookup',
            'complexity' => 'O(1)',
            'fanout_factor' => 1.0,
        ];

        $scores = $this->engine->score($metrics);

        $this->assertContains($scores['grade'], ['A+', 'A']);
        $this->assertGreaterThanOrEqual(90.0, $scores['composite_score']);
    }

    public function test_full_scan_query_grades_low(): void
    {
        $metrics = [
            'execution_time_ms' => 5000.0,
            'rows_examined' => 500_000,
            'rows_returned' => 50,
            'nested_loop_depth' => 0,
            'has_table_scan' => true,
            'has_filesort' => true,
            'has_temp_table' => true,
            'has_weedout' => false,
            'has_index_merge' => false,
            'has_covering_index' => false,
            'is_index_backed' => false,
            'has_early_termination' => false,
            'is_zero_row_const' => false,
            'primary_access_type' => 'table_scan',
            'complexity' => 'O(n)',
            'fanout_factor' => 500_000.0,
        ];

        $scores = $this->engine->score($metrics);

        $this->assertContains($scores['grade'], ['D', 'F']);
        $this->assertLessThan(50.0, $scores['composite_score']);
    }

    public function test_breakdown_contains_all_components(): void
    {
        $metrics = $this->makeMetrics();

        $scores = $this->engine->score($metrics);

        $this->assertArrayHasKey('breakdown', $scores);
        $breakdown = $scores['breakdown'];

        $this->assertArrayHasKey('execution_time', $breakdown);
        $this->assertArrayHasKey('scan_efficiency', $breakdown);
        $this->assertArrayHasKey('index_quality', $breakdown);
        $this->assertArrayHasKey('join_efficiency', $breakdown);
        $this->assertArrayHasKey('scalability', $breakdown);

        foreach ($breakdown as $component => $data) {
            $this->assertArrayHasKey('score', $data);
            $this->assertArrayHasKey('weight', $data);
            $this->assertArrayHasKey('weighted', $data);
            $this->assertGreaterThanOrEqual(0, $data['score']);
            $this->assertLessThanOrEqual(100, $data['score']);
        }
    }

    public function test_weights_sum_correctly(): void
    {
        $metrics = $this->makeMetrics();
        $scores = $this->engine->score($metrics);

        $weightSum = 0;
        foreach ($scores['breakdown'] as $data) {
            $weightSum += $data['weight'];
        }

        $this->assertEqualsWithDelta(1.0, $weightSum, 0.01);
    }

    public function test_context_override_promotes_to_a_plus(): void
    {
        $metrics = [
            'execution_time_ms' => 0.5,
            'rows_examined' => 100,
            'rows_returned' => 50,
            'nested_loop_depth' => 4,
            'has_table_scan' => false,
            'has_filesort' => false,
            'has_temp_table' => false,
            'has_weedout' => false,
            'has_index_merge' => false,
            'has_covering_index' => true,
            'is_index_backed' => true,
            'has_early_termination' => true,
            'is_zero_row_const' => false,
            'primary_access_type' => 'covering_index_lookup',
            'complexity' => 'O(1)',
            'fanout_factor' => 200.0,
        ];

        $scores = $this->engine->score($metrics);

        $this->assertTrue($scores['context_override']);
        $this->assertContains($scores['grade'], ['A+', 'A']);
        $this->assertGreaterThanOrEqual(95.0, $scores['composite_score']);
    }

    public function test_zero_row_const_gets_perfect_score(): void
    {
        $metrics = [
            'execution_time_ms' => 0.003,
            'rows_examined' => 0,
            'rows_returned' => 0,
            'nested_loop_depth' => 0,
            'has_table_scan' => false,
            'has_filesort' => false,
            'has_temp_table' => false,
            'has_weedout' => false,
            'has_index_merge' => false,
            'has_covering_index' => false,
            'is_index_backed' => true,
            'has_early_termination' => false,
            'is_zero_row_const' => true,
            'primary_access_type' => 'zero_row_const',
            'complexity' => 'O(1)',
            'fanout_factor' => 1.0,
        ];

        $scores = $this->engine->score($metrics);

        $this->assertSame('A+', $scores['grade']);
        $this->assertGreaterThanOrEqual(98.0, $scores['composite_score']);
        $this->assertSame(100, $scores['breakdown']['index_quality']['score']);
        $this->assertSame(100, $scores['breakdown']['scalability']['score']);
    }

    public function test_custom_weights_applied(): void
    {
        $engine = new DefaultScoringEngine(
            weights: [
                'execution_time' => 1.0,
                'scan_efficiency' => 0.0,
                'index_quality' => 0.0,
                'join_efficiency' => 0.0,
                'scalability' => 0.0,
            ]
        );

        // Very fast query
        $metrics = $this->makeMetrics(['execution_time_ms' => 0.01]);
        $scores = $engine->score($metrics);

        // With all weight on execution_time and 0.01ms → score ~100
        $this->assertGreaterThanOrEqual(90.0, $scores['composite_score']);
    }

    public function test_execution_time_scoring_tiers(): void
    {
        $engine = new DefaultScoringEngine;

        // Sub-millisecond → 100
        $s1 = $engine->score($this->makeMetrics(['execution_time_ms' => 0.5]));
        $this->assertSame(100, $s1['breakdown']['execution_time']['score']);

        // 500ms → somewhere in the middle
        $s2 = $engine->score($this->makeMetrics(['execution_time_ms' => 500.0]));
        $this->assertGreaterThan(30, $s2['breakdown']['execution_time']['score']);
        $this->assertLessThan(70, $s2['breakdown']['execution_time']['score']);

        // Very slow → near zero
        $s3 = $engine->score($this->makeMetrics(['execution_time_ms' => 15000.0]));
        $this->assertLessThanOrEqual(10, $s3['breakdown']['execution_time']['score']);
    }

    public function test_scan_efficiency_perfect_selectivity(): void
    {
        $metrics = $this->makeMetrics([
            'rows_examined' => 50,
            'rows_returned' => 50,
        ]);

        $scores = $this->engine->score($metrics);

        $this->assertSame(100, $scores['breakdown']['scan_efficiency']['score']);
    }

    public function test_scan_efficiency_poor_selectivity(): void
    {
        $metrics = $this->makeMetrics([
            'rows_examined' => 100_000,
            'rows_returned' => 10,
        ]);

        $scores = $this->engine->score($metrics);

        $this->assertLessThan(30, $scores['breakdown']['scan_efficiency']['score']);
    }

    public function test_index_quality_deductions(): void
    {
        // Table scan penalty
        $m1 = $this->makeMetrics(['has_table_scan' => true, 'is_index_backed' => false, 'primary_access_type' => 'table_scan']);
        $s1 = $this->engine->score($m1);
        $this->assertLessThanOrEqual(30, $s1['breakdown']['index_quality']['score']);

        // No table scan, good index = high score
        $m2 = $this->makeMetrics([
            'has_table_scan' => false,
            'is_index_backed' => true,
            'has_covering_index' => true,
        ]);
        $s2 = $this->engine->score($m2);
        $this->assertSame(100, $s2['breakdown']['index_quality']['score']);
    }

    // ---------------------------------------------------------------
    // Scalability scoring tiers
    // ---------------------------------------------------------------

    public function test_scalability_score_constant(): void
    {
        $metrics = $this->makeMetrics(['complexity' => 'O(1)']);
        $scores = $this->engine->score($metrics);
        $this->assertSame(100, $scores['breakdown']['scalability']['score']);
    }

    public function test_scalability_score_logarithmic(): void
    {
        $metrics = $this->makeMetrics(['complexity' => 'O(log n)']);
        $scores = $this->engine->score($metrics);
        $this->assertSame(90, $scores['breakdown']['scalability']['score']);
    }

    public function test_scalability_score_log_range(): void
    {
        $metrics = $this->makeMetrics(['complexity' => 'O(log n + k)']);
        $scores = $this->engine->score($metrics);
        $this->assertSame(80, $scores['breakdown']['scalability']['score']);
    }

    public function test_scalability_score_linear(): void
    {
        $metrics = $this->makeMetrics(['complexity' => 'O(n)']);
        $scores = $this->engine->score($metrics);
        $this->assertSame(50, $scores['breakdown']['scalability']['score']);
    }

    public function test_scalability_score_linearithmic(): void
    {
        $metrics = $this->makeMetrics(['complexity' => 'O(n log n)']);
        $scores = $this->engine->score($metrics);
        $this->assertSame(30, $scores['breakdown']['scalability']['score']);
    }

    public function test_scalability_score_quadratic(): void
    {
        $metrics = $this->makeMetrics(['complexity' => 'O(n²)']);
        $scores = $this->engine->score($metrics);
        $this->assertSame(10, $scores['breakdown']['scalability']['score']);
    }

    public function test_scalability_early_termination_bonus(): void
    {
        // O(n) base = 50, early termination adds 20 → 70
        $metrics = $this->makeMetrics([
            'complexity' => 'O(n)',
            'has_early_termination' => true,
        ]);
        $scores = $this->engine->score($metrics);
        $this->assertSame(70, $scores['breakdown']['scalability']['score']);
    }

    public function test_scalability_early_termination_capped_at_100(): void
    {
        // O(1) base = 100, early termination doesn't exceed 100
        $metrics = $this->makeMetrics([
            'complexity' => 'O(1)',
            'has_early_termination' => true,
        ]);
        $scores = $this->engine->score($metrics);
        $this->assertSame(100, $scores['breakdown']['scalability']['score']);
    }

    // ---------------------------------------------------------------
    // Join efficiency tiers
    // ---------------------------------------------------------------

    public function test_join_efficiency_shallow_depth_perfect(): void
    {
        $metrics = $this->makeMetrics(['nested_loop_depth' => 1, 'fanout_factor' => 1.0]);
        $scores = $this->engine->score($metrics);
        $this->assertSame(100, $scores['breakdown']['join_efficiency']['score']);
    }

    public function test_join_efficiency_depth_3_reduced(): void
    {
        $metrics = $this->makeMetrics(['nested_loop_depth' => 3, 'fanout_factor' => 1.0]);
        $scores = $this->engine->score($metrics);
        $this->assertSame(80, $scores['breakdown']['join_efficiency']['score']);
    }

    public function test_join_efficiency_high_fanout_penalized(): void
    {
        $metrics = $this->makeMetrics(['nested_loop_depth' => 1, 'fanout_factor' => 50_000.0]);
        $scores = $this->engine->score($metrics);
        $this->assertSame(70, $scores['breakdown']['join_efficiency']['score']);
    }

    public function test_join_efficiency_weedout_penalty(): void
    {
        $metrics = $this->makeMetrics(['nested_loop_depth' => 1, 'has_weedout' => true, 'fanout_factor' => 1.0]);
        $scores = $this->engine->score($metrics);
        $this->assertSame(85, $scores['breakdown']['join_efficiency']['score']);
    }

    // ---------------------------------------------------------------
    // Context override for const access types
    // ---------------------------------------------------------------

    public function test_context_override_for_const_row(): void
    {
        $metrics = $this->makeMetrics([
            'primary_access_type' => 'const_row',
            'execution_time_ms' => 0.01,
            'complexity' => 'O(1)',
            // Add some penalty to bring score below 98 so override can trigger
            'nested_loop_depth' => 4,
            'fanout_factor' => 200.0,
        ]);
        $scores = $this->engine->score($metrics);
        $this->assertTrue($scores['context_override']);
        $this->assertSame('A+', $scores['grade']);
    }

    public function test_context_override_for_single_row_lookup(): void
    {
        $metrics = $this->makeMetrics([
            'primary_access_type' => 'single_row_lookup',
            'execution_time_ms' => 0.05,
            'complexity' => 'O(1)',
            // Add some penalty to bring score below 98 so override can trigger
            'nested_loop_depth' => 4,
            'fanout_factor' => 200.0,
        ]);
        $scores = $this->engine->score($metrics);
        $this->assertTrue($scores['context_override']);
        $this->assertSame('A+', $scores['grade']);
    }

    public function test_no_context_override_for_slow_const(): void
    {
        // Const access but very slow (> 10ms) — no override
        $metrics = $this->makeMetrics([
            'primary_access_type' => 'const_row',
            'execution_time_ms' => 50.0,
            'complexity' => 'O(1)',
            'has_early_termination' => false,
            'has_covering_index' => false,
        ]);
        $scores = $this->engine->score($metrics);
        $this->assertFalse($scores['context_override']);
    }

    // ---------------------------------------------------------------
    // Scan efficiency edge cases
    // ---------------------------------------------------------------

    public function test_scan_efficiency_zero_rows_examined_zero_returned(): void
    {
        $metrics = $this->makeMetrics([
            'rows_examined' => 0,
            'rows_returned' => 0,
        ]);
        $scores = $this->engine->score($metrics);
        $this->assertSame(100, $scores['breakdown']['scan_efficiency']['score']);
    }

    // ---------------------------------------------------------------
    // Grade thresholds
    // ---------------------------------------------------------------

    public function test_grade_a_plus_threshold(): void
    {
        // Force a score >= 98 via zero-row const
        $metrics = [
            'execution_time_ms' => 0.003,
            'rows_examined' => 0,
            'rows_returned' => 0,
            'nested_loop_depth' => 0,
            'has_table_scan' => false,
            'has_filesort' => false,
            'has_temp_table' => false,
            'has_weedout' => false,
            'has_index_merge' => false,
            'has_covering_index' => false,
            'is_index_backed' => true,
            'has_early_termination' => false,
            'is_zero_row_const' => true,
            'primary_access_type' => 'zero_row_const',
            'complexity' => 'O(1)',
            'fanout_factor' => 1.0,
        ];
        $scores = $this->engine->score($metrics);
        $this->assertSame('A+', $scores['grade']);
        $this->assertGreaterThanOrEqual(98.0, $scores['composite_score']);
    }

    public function test_grade_f_threshold(): void
    {
        $metrics = $this->makeMetrics([
            'execution_time_ms' => 30000.0,
            'rows_examined' => 1_000_000,
            'rows_returned' => 10,
            'has_table_scan' => true,
            'is_index_backed' => false,
            'has_covering_index' => false,
            'has_filesort' => true,
            'has_temp_table' => true,
            'has_weedout' => true,
            'nested_loop_depth' => 5,
            'fanout_factor' => 100_000.0,
            'primary_access_type' => 'table_scan',
            'complexity' => 'O(n²)',
        ]);
        $scores = $this->engine->score($metrics);
        $this->assertSame('F', $scores['grade']);
        $this->assertLessThan(25.0, $scores['composite_score']);
    }

    // ---------------------------------------------------------------
    // Index quality for const access types
    // ---------------------------------------------------------------

    public function test_index_quality_perfect_for_const_row(): void
    {
        $metrics = $this->makeMetrics([
            'primary_access_type' => 'const_row',
            'is_zero_row_const' => false,
        ]);
        $scores = $this->engine->score($metrics);
        $this->assertSame(100, $scores['breakdown']['index_quality']['score']);
    }

    public function test_index_quality_perfect_for_single_row_lookup(): void
    {
        $metrics = $this->makeMetrics([
            'primary_access_type' => 'single_row_lookup',
            'is_zero_row_const' => false,
        ]);
        $scores = $this->engine->score($metrics);
        $this->assertSame(100, $scores['breakdown']['index_quality']['score']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function makeMetrics(array $overrides = []): array
    {
        return array_merge([
            'execution_time_ms' => 1.0,
            'rows_examined' => 100,
            'rows_returned' => 50,
            'nested_loop_depth' => 1,
            'has_table_scan' => false,
            'has_filesort' => false,
            'has_temp_table' => false,
            'has_weedout' => false,
            'has_index_merge' => false,
            'has_covering_index' => true,
            'is_index_backed' => true,
            'has_early_termination' => false,
            'is_zero_row_const' => false,
            'primary_access_type' => 'index_lookup',
            'complexity' => 'O(log n)',
            'fanout_factor' => 1.0,
        ], $overrides);
    }
}
