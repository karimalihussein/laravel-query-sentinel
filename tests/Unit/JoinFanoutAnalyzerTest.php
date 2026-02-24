<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QuerySentinel\Analyzers\JoinAnalyzer;
use QuerySentinel\Enums\Severity;
use QuerySentinel\Support\Finding;

final class JoinFanoutAnalyzerTest extends TestCase
{
    private JoinAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new JoinAnalyzer;
    }

    // ---------------------------------------------------------------
    // 1. Single table plan produces contained risk
    // ---------------------------------------------------------------

    public function test_simple_single_table_contained_risk(): void
    {
        $plan = <<<'PLAN'
-> Table scan on users (actual time=0.1..5.0 rows=100 loops=1)
PLAN;

        $metrics = [
            'per_table_estimates' => [
                'users' => ['actual_rows' => 100, 'loops' => 1],
            ],
            'rows_returned' => 100,
        ];

        $result = $this->analyzer->analyze($plan, $metrics, []);

        $this->assertSame('contained', $result['join_analysis']['multiplicative_risk']);
    }

    // ---------------------------------------------------------------
    // 2. Effective fanout from multi-table plan
    // ---------------------------------------------------------------

    public function test_effective_fanout_from_plan(): void
    {
        // 3 (actual time=...) patterns in the plan:
        //   join node:  rows=250000, loops=1 -> 250000
        //   users:      rows=50000, loops=1  -> 50000
        //   orders:     rows=5, loops=50000  -> 250000
        // Product = 250000 * 50000 * 250000
        $plan = <<<'PLAN'
-> Nested loop inner join (actual time=0.1..500.0 rows=250000 loops=1)
    -> Table scan on users (actual time=0.1..10.0 rows=50000 loops=1)
    -> Index lookup on orders using idx_user_id (actual time=0.01..0.02 rows=5 loops=50000)
PLAN;

        $metrics = [
            'per_table_estimates' => [
                'users' => ['actual_rows' => 50000, 'loops' => 1],
                'orders' => ['actual_rows' => 5, 'loops' => 50000],
            ],
            'rows_returned' => 250000,
        ];

        $result = $this->analyzer->analyze($plan, $metrics, []);

        // Product of all (rows*loops) from every (actual time=...) match
        $expectedFanout = 250000.0 * 50000.0 * 250000.0;
        $this->assertSame($expectedFanout, $result['join_analysis']['effective_fanout']);
    }

    // ---------------------------------------------------------------
    // 3. Explosion factor = effective_fanout / driving_rows
    // ---------------------------------------------------------------

    public function test_explosion_factor_calculation(): void
    {
        $plan = <<<'PLAN'
-> Nested loop inner join (actual time=0.1..50.0 rows=500 loops=1)
    -> Table scan on users (actual time=0.1..1.0 rows=100 loops=1)
    -> Index lookup on orders using idx_user_id (actual time=0.01..0.02 rows=5 loops=100)
PLAN;

        $metrics = [
            'per_table_estimates' => [
                'users' => ['actual_rows' => 100, 'loops' => 1],
                'orders' => ['actual_rows' => 5, 'loops' => 100],
            ],
            'rows_returned' => 500,
        ];

        $result = $this->analyzer->analyze($plan, $metrics, []);

        $effectiveFanout = $result['join_analysis']['effective_fanout'];
        // Driving table = users: 100 * 1 = 100
        $expectedExplosion = $effectiveFanout / 100.0;
        $this->assertSame($expectedExplosion, $result['join_analysis']['explosion_factor']);
    }

    // ---------------------------------------------------------------
    // 4. Multiplicative risk: contained (factor < 10)
    // ---------------------------------------------------------------

    public function test_multiplicative_risk_contained(): void
    {
        // Single (actual time=...) match: rows=5, loops=1 -> effective_fanout = 5
        // Driving table = 5 rows -> explosion_factor = 5/5 = 1
        $plan = <<<'PLAN'
-> Index lookup on users using PRIMARY (actual time=0.01..0.05 rows=5 loops=1)
PLAN;

        $metrics = [
            'per_table_estimates' => [
                'users' => ['actual_rows' => 5, 'loops' => 1],
            ],
            'rows_returned' => 5,
        ];

        $result = $this->analyzer->analyze($plan, $metrics, []);

        $this->assertSame('contained', $result['join_analysis']['multiplicative_risk']);
    }

    // ---------------------------------------------------------------
    // 5. Multiplicative risk: linear_amplification (factor 10-100)
    // ---------------------------------------------------------------

    public function test_multiplicative_risk_linear_amplification(): void
    {
        // Two (actual time=...) matches (no join node with actual time):
        //   users:  rows=10, loops=1 -> 10
        //   orders: rows=5, loops=10 -> 50
        // effective_fanout = 10 * 50 = 500
        // driving = 10
        // explosion = 500 / 10 = 50  => linear_amplification (>10 and <=100)
        $plan = <<<'PLAN'
-> Table scan on users (actual time=0.1..1.0 rows=10 loops=1)
-> Index lookup on orders using idx_user_id (actual time=0.01..0.02 rows=5 loops=10)
PLAN;

        $metrics = [
            'per_table_estimates' => [
                'users' => ['actual_rows' => 10, 'loops' => 1],
                'orders' => ['actual_rows' => 5, 'loops' => 10],
            ],
            'rows_returned' => 50,
        ];

        $result = $this->analyzer->analyze($plan, $metrics, []);

        $this->assertSame('linear_amplification', $result['join_analysis']['multiplicative_risk']);
        $this->assertGreaterThan(10, $result['join_analysis']['explosion_factor']);
        $this->assertLessThanOrEqual(100, $result['join_analysis']['explosion_factor']);
    }

    // ---------------------------------------------------------------
    // 6. Multiplicative risk: multiplicative_risk (factor 100-1000)
    // ---------------------------------------------------------------

    public function test_multiplicative_risk_multiplicative(): void
    {
        // Two (actual time=...) matches (no join node with actual time):
        //   users:  rows=10, loops=1  -> 10
        //   orders: rows=50, loops=10 -> 500
        // effective_fanout = 10 * 500 = 5000
        // driving = 10
        // explosion = 5000 / 10 = 500  => multiplicative_risk (>100 and <=1000)
        $plan = <<<'PLAN'
-> Table scan on users (actual time=0.1..1.0 rows=10 loops=1)
-> Index lookup on orders using idx_user_id (actual time=0.01..0.05 rows=50 loops=10)
PLAN;

        $metrics = [
            'per_table_estimates' => [
                'users' => ['actual_rows' => 10, 'loops' => 1],
                'orders' => ['actual_rows' => 50, 'loops' => 10],
            ],
            'rows_returned' => 500,
        ];

        $result = $this->analyzer->analyze($plan, $metrics, []);

        $this->assertSame('multiplicative_risk', $result['join_analysis']['multiplicative_risk']);
        $this->assertGreaterThan(100, $result['join_analysis']['explosion_factor']);
        $this->assertLessThanOrEqual(1000, $result['join_analysis']['explosion_factor']);
    }

    // ---------------------------------------------------------------
    // 7. Multiplicative risk: exponential_explosion (factor > 1000)
    // ---------------------------------------------------------------

    public function test_multiplicative_risk_exponential(): void
    {
        // Two (actual time=...) matches (no join node with actual time):
        //   users:  rows=10, loops=1   -> 10
        //   orders: rows=200, loops=10 -> 2000
        // effective_fanout = 10 * 2000 = 20000
        // driving = 10
        // explosion = 20000 / 10 = 2000  => exponential_explosion (>1000)
        $plan = <<<'PLAN'
-> Table scan on users (actual time=0.1..1.0 rows=10 loops=1)
-> Index lookup on orders using idx_user_id (actual time=0.01..0.05 rows=200 loops=10)
PLAN;

        $metrics = [
            'per_table_estimates' => [
                'users' => ['actual_rows' => 10, 'loops' => 1],
                'orders' => ['actual_rows' => 200, 'loops' => 10],
            ],
            'rows_returned' => 2000,
        ];

        $result = $this->analyzer->analyze($plan, $metrics, []);

        $this->assertSame('exponential_explosion', $result['join_analysis']['multiplicative_risk']);
        $this->assertGreaterThan(1000, $result['join_analysis']['explosion_factor']);
    }

    // ---------------------------------------------------------------
    // 8. Per-step fanout extracted from plan with 2 tables
    // ---------------------------------------------------------------

    public function test_per_step_fanout_extracted(): void
    {
        $plan = <<<'PLAN'
-> Nested loop inner join (actual time=0.1..50.0 rows=500 loops=1)
    -> Table scan on users (actual time=0.1..1.0 rows=100 loops=1)
    -> Index lookup on orders using idx_user_id (actual time=0.01..0.02 rows=5 loops=100)
PLAN;

        $metrics = [];
        $result = $this->analyzer->analyze($plan, $metrics, []);

        $perStep = $result['join_analysis']['per_step'];
        $this->assertCount(2, $perStep);

        // First step: users
        $this->assertSame('users', $perStep[0]['table']);
        $this->assertSame(100, $perStep[0]['rows']);
        $this->assertSame(1, $perStep[0]['loops']);
        $this->assertSame(100.0, $perStep[0]['step_fanout']);

        // Second step: orders
        $this->assertSame('orders', $perStep[1]['table']);
        $this->assertSame(5, $perStep[1]['rows']);
        $this->assertSame(100, $perStep[1]['loops']);
        $this->assertSame(500.0, $perStep[1]['step_fanout']);
    }

    // ---------------------------------------------------------------
    // 9. Nested loop detected in plan
    // ---------------------------------------------------------------

    public function test_nested_loop_detected(): void
    {
        $plan = <<<'PLAN'
-> Nested loop inner join (actual time=0.1..50.0 rows=500 loops=1)
    -> Table scan on users (actual time=0.1..1.0 rows=100 loops=1)
    -> Index lookup on orders using idx_user_id (actual time=0.01..0.02 rows=5 loops=100)
PLAN;

        $result = $this->analyzer->analyze($plan, [], []);

        $this->assertContains('nested_loop', $result['join_analysis']['join_types']);
    }

    // ---------------------------------------------------------------
    // 10. Hash join detected produces info finding
    // ---------------------------------------------------------------

    public function test_hash_join_detected(): void
    {
        $plan = <<<'PLAN'
-> Hash join (actual time=10.0..50.0 rows=500 loops=1)
    -> Table scan on users (actual time=0.1..1.0 rows=100 loops=1)
    -> Table scan on orders (actual time=0.1..2.0 rows=500 loops=1)
PLAN;

        $result = $this->analyzer->analyze($plan, [], []);

        $this->assertContains('hash_join', $result['join_analysis']['join_types']);

        $finding = $this->findFindingByTitle($result, 'Hash join detected');
        $this->assertNotNull($finding);
        $this->assertSame(Severity::Info, $finding->severity);
        $this->assertSame('join_analysis', $finding->category);
    }

    // ---------------------------------------------------------------
    // 11. Block Nested Loop detected produces warning finding
    // ---------------------------------------------------------------

    public function test_bnl_detected(): void
    {
        $plan = <<<'PLAN'
-> Block Nested Loop inner join (actual time=5.0..500.0 rows=5000 loops=1)
    -> Table scan on users (actual time=0.1..1.0 rows=100 loops=1)
    -> Table scan on orders (actual time=0.1..5.0 rows=50 loops=100)
PLAN;

        $result = $this->analyzer->analyze($plan, [], []);

        $this->assertContains('block_nested_loop', $result['join_analysis']['join_types']);

        $finding = $this->findFindingByTitle($result, 'Block Nested Loop (BNL) detected');
        $this->assertNotNull($finding);
        $this->assertSame(Severity::Warning, $finding->severity);
        $this->assertSame('join_analysis', $finding->category);
    }

    // ---------------------------------------------------------------
    // 12. High fanout multiplier (> 10) produces warning finding
    // ---------------------------------------------------------------

    public function test_high_fanout_multiplier_warning(): void
    {
        // users: 100*1=100, orders: 20*100=2000 -> multiplier = 2000/100 = 20
        $plan = <<<'PLAN'
-> Nested loop inner join (actual time=0.1..50.0 rows=2000 loops=1)
    -> Table scan on users (actual time=0.1..1.0 rows=100 loops=1)
    -> Index lookup on orders using idx_user_id (actual time=0.01..0.02 rows=20 loops=100)
PLAN;

        $result = $this->analyzer->analyze($plan, [], []);

        $fanout = $result['join_analysis']['fanout_multiplier'];
        $this->assertGreaterThan(10.0, $fanout);
        $this->assertLessThanOrEqual(100.0, $fanout);

        $finding = $this->findFindingByTitlePrefix($result, 'Join fanout risk:');
        $this->assertNotNull($finding);
        $this->assertSame(Severity::Warning, $finding->severity);
    }

    // ---------------------------------------------------------------
    // 13. Critical fanout multiplier (> 100) produces critical finding
    // ---------------------------------------------------------------

    public function test_critical_fanout_multiplier(): void
    {
        // users: 10*1=10, orders: 500*10=5000 -> multiplier = 5000/10 = 500
        $plan = <<<'PLAN'
-> Nested loop inner join (actual time=0.1..500.0 rows=5000 loops=1)
    -> Table scan on users (actual time=0.1..1.0 rows=10 loops=1)
    -> Index lookup on orders using idx_user_id (actual time=0.01..0.05 rows=500 loops=10)
PLAN;

        $result = $this->analyzer->analyze($plan, [], []);

        $fanout = $result['join_analysis']['fanout_multiplier'];
        $this->assertGreaterThan(100.0, $fanout);

        $finding = $this->findFindingByTitlePrefix($result, 'Join fanout risk:');
        $this->assertNotNull($finding);
        $this->assertSame(Severity::Critical, $finding->severity);
    }

    // ---------------------------------------------------------------
    // 14. Lookup efficiency: covering index detected
    // ---------------------------------------------------------------

    public function test_lookup_efficiency_covering(): void
    {
        $plan = '-> Index lookup on users using PRIMARY (actual time=0.01..0.05 rows=1 loops=1)';

        $explainRows = [
            ['table' => 'users', 'Extra' => 'Using index'],
        ];

        $result = $this->analyzer->analyze($plan, [], $explainRows);

        $this->assertSame('covering', $result['join_analysis']['lookup_efficiency']['users']);
    }

    // ---------------------------------------------------------------
    // 15. Lookup efficiency: full_row_fetch when no "Using index"
    // ---------------------------------------------------------------

    public function test_lookup_efficiency_full_row(): void
    {
        $plan = '-> Index lookup on users using PRIMARY (actual time=0.01..0.05 rows=1 loops=1)';

        $explainRows = [
            ['table' => 'users', 'Extra' => 'Using where'],
        ];

        $result = $this->analyzer->analyze($plan, [], $explainRows);

        $this->assertSame('full_row_fetch', $result['join_analysis']['lookup_efficiency']['users']);
    }

    // ---------------------------------------------------------------
    // 16. Multiplicative risk generates a Warning finding
    // ---------------------------------------------------------------

    public function test_multiplicative_risk_generates_finding(): void
    {
        // Two (actual time=...) matches (no join node with actual time):
        //   users:  rows=10, loops=1  -> 10
        //   orders: rows=50, loops=10 -> 500
        // effective_fanout = 10 * 500 = 5000
        // driving = 10
        // explosion = 5000 / 10 = 500 -> multiplicative_risk (>100 and <=1000)
        $plan = <<<'PLAN'
-> Table scan on users (actual time=0.1..1.0 rows=10 loops=1)
-> Index lookup on orders using idx_user_id (actual time=0.01..0.05 rows=50 loops=10)
PLAN;

        $metrics = [
            'per_table_estimates' => [
                'users' => ['actual_rows' => 10, 'loops' => 1],
                'orders' => ['actual_rows' => 50, 'loops' => 10],
            ],
            'rows_returned' => 500,
        ];

        $result = $this->analyzer->analyze($plan, $metrics, []);

        $this->assertSame('multiplicative_risk', $result['join_analysis']['multiplicative_risk']);

        $finding = $this->findFindingByTitlePrefix($result, 'Multiplicative join risk:');
        $this->assertNotNull($finding, 'Expected a Multiplicative join risk finding');
        $this->assertSame(Severity::Warning, $finding->severity);
        $this->assertSame('join_analysis', $finding->category);
        $this->assertArrayHasKey('effective_fanout', $finding->metadata);
        $this->assertArrayHasKey('explosion_factor', $finding->metadata);
        $this->assertSame('multiplicative_risk', $finding->metadata['risk']);
    }

    // ---------------------------------------------------------------
    // 17. Exponential explosion generates a Critical finding
    // ---------------------------------------------------------------

    public function test_exponential_explosion_generates_critical_finding(): void
    {
        // Two (actual time=...) matches (no join node with actual time):
        //   users:  rows=10, loops=1   -> 10
        //   orders: rows=200, loops=10 -> 2000
        // effective_fanout = 10 * 2000 = 20000
        // driving = 10
        // explosion = 20000 / 10 = 2000 -> exponential_explosion (>1000)
        $plan = <<<'PLAN'
-> Table scan on users (actual time=0.1..1.0 rows=10 loops=1)
-> Index lookup on orders using idx_user_id (actual time=0.01..0.05 rows=200 loops=10)
PLAN;

        $metrics = [
            'per_table_estimates' => [
                'users' => ['actual_rows' => 10, 'loops' => 1],
                'orders' => ['actual_rows' => 200, 'loops' => 10],
            ],
            'rows_returned' => 2000,
        ];

        $result = $this->analyzer->analyze($plan, $metrics, []);

        $this->assertSame('exponential_explosion', $result['join_analysis']['multiplicative_risk']);

        $finding = $this->findFindingByTitlePrefix($result, 'Multiplicative join risk:');
        $this->assertNotNull($finding, 'Expected a Multiplicative join risk finding');
        $this->assertSame(Severity::Critical, $finding->severity);
        $this->assertSame('join_analysis', $finding->category);
        $this->assertStringContainsString('exponential_explosion', $finding->title);
        $this->assertSame('exponential_explosion', $finding->metadata['risk']);
    }

    // ---------------------------------------------------------------
    // 18. Empty plan produces safe defaults
    // ---------------------------------------------------------------

    public function test_empty_plan_defaults(): void
    {
        $result = $this->analyzer->analyze('', [], []);

        $this->assertSame(['simple'], $result['join_analysis']['join_types']);
        $this->assertSame(1.0, $result['join_analysis']['fanout_multiplier']);
        $this->assertSame(1.0, $result['join_analysis']['effective_fanout']);
        $this->assertSame('contained', $result['join_analysis']['multiplicative_risk']);
        $this->assertEmpty($result['join_analysis']['per_step']);
        $this->assertEmpty($result['join_analysis']['lookup_efficiency']);
        $this->assertEmpty($result['findings']);
    }

    // ---------------------------------------------------------------
    // Helper methods
    // ---------------------------------------------------------------

    /**
     * @param  array{join_analysis: mixed, findings: Finding[]}  $result
     */
    private function findFindingByTitle(array $result, string $title): ?Finding
    {
        foreach ($result['findings'] as $finding) {
            if ($finding->title === $title) {
                return $finding;
            }
        }

        return null;
    }

    /**
     * @param  array{join_analysis: mixed, findings: Finding[]}  $result
     */
    private function findFindingByTitlePrefix(array $result, string $prefix): ?Finding
    {
        foreach ($result['findings'] as $finding) {
            if (str_starts_with($finding->title, $prefix)) {
                return $finding;
            }
        }

        return null;
    }
}
