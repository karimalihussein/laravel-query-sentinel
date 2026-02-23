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

    public function test_perfect_query_grades_a(): void
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
            'complexity' => 'O(limit)',
            'fanout_factor' => 1.0,
        ];

        $scores = $this->engine->score($metrics);

        $this->assertSame('A', $scores['grade']);
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

    public function test_context_override_promotes_to_a(): void
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
            'complexity' => 'O(limit)',
            'fanout_factor' => 200.0,
        ];

        $scores = $this->engine->score($metrics);

        $this->assertTrue($scores['context_override']);
        $this->assertSame('A', $scores['grade']);
        $this->assertGreaterThanOrEqual(95.0, $scores['composite_score']);
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
        $m1 = $this->makeMetrics(['has_table_scan' => true, 'is_index_backed' => false]);
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
            'complexity' => 'O(range)',
            'fanout_factor' => 1.0,
        ], $overrides);
    }
}
