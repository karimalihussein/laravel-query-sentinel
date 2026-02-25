<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Unit;

use QuerySentinel\Analyzers\PlanStabilityAnalyzer;
use QuerySentinel\Enums\Severity;
use QuerySentinel\Support\Finding;
use QuerySentinel\Tests\TestCase;

/**
 * Unit tests for Phase 8: Volatility scoring and drift integration
 * in the PlanStabilityAnalyzer.
 *
 * Uses Orchestra TestCase so that DB/Cache facades are available
 * (the detectStatisticsDrift method uses them internally).
 * We pass empty explainRows and null connectionName to avoid actual DB calls.
 */
final class PlanStabilityVolatilityTest extends TestCase
{
    private PlanStabilityAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new PlanStabilityAnalyzer;
    }

    // ---------------------------------------------------------------
    // 1. Stable plan - low volatility
    // ---------------------------------------------------------------

    public function test_stable_plan_low_volatility(): void
    {
        // No deviations (estimates match actuals within 5x), no drift data
        $plan = "-> Index lookup on users using PRIMARY (cost=1.00 rows=10) (actual time=0.01..0.02 rows=8 loops=1)\n";

        $result = $this->analyzer->analyze('SELECT * FROM users WHERE id = 1', $plan, [], [], null, null);

        $stability = $result['stability'];
        $this->assertLessThan(30, $stability['volatility_score']);
        $this->assertSame('stable', $stability['volatility_label']);
    }

    // ---------------------------------------------------------------
    // 2. Deviations increase volatility
    // ---------------------------------------------------------------

    public function test_deviations_increase_volatility(): void
    {
        // Large deviation: estimated=1000, actual=10 → factor=100 → contributes min(100*5, 25)=25
        $plan = "-> Table scan on orders (cost=500.00 rows=1000) (actual time=0.10..1.00 rows=10 loops=1)\n";

        $result = $this->analyzer->analyze('SELECT * FROM orders', $plan, [], [], null, null);

        $stability = $result['stability'];
        // factor = 1000/10 = 100 → deviation contributes 25 (capped)
        $this->assertGreaterThanOrEqual(25, $stability['volatility_score']);
    }

    // ---------------------------------------------------------------
    // 3. Optimizer hints reduce volatility
    // ---------------------------------------------------------------

    public function test_optimizer_hints_reduce_volatility(): void
    {
        // Plan with a deviation (factor ~10 → contributes min(10*5, 25)=25)
        // But FORCE INDEX hint should reduce by 20 → net ~5
        $plan = "-> Index lookup on users using idx_name (cost=10.00 rows=100) (actual time=0.10..0.50 rows=10 loops=1)\n";

        $resultWithHint = $this->analyzer->analyze(
            'SELECT * FROM users FORCE INDEX (idx_name) WHERE name = "test"',
            $plan,
            [],
            [],
            null,
            null,
        );

        $resultWithoutHint = $this->analyzer->analyze(
            'SELECT * FROM users WHERE name = "test"',
            $plan,
            [],
            [],
            null,
            null,
        );

        $this->assertLessThan(
            $resultWithoutHint['stability']['volatility_score'],
            $resultWithHint['stability']['volatility_score'],
            'Optimizer hints should reduce volatility score'
        );

        // With hint: 25 (deviation) - 20 (hint) = 5
        $this->assertSame(5, $resultWithHint['stability']['volatility_score']);
    }

    // ---------------------------------------------------------------
    // 4. High cardinality drift increases volatility
    // ---------------------------------------------------------------

    public function test_high_cardinality_drift_increases_volatility(): void
    {
        // No deviations in plan, but high drift score
        $plan = "-> Index lookup on users using PRIMARY (cost=1.00 rows=10) (actual time=0.01..0.02 rows=8 loops=1)\n";

        $drift = [
            'composite_drift_score' => 0.8,
            'per_table' => [
                'users' => ['drift_ratio' => 0.8, 'drift_direction' => 'under'],
            ],
        ];

        $result = $this->analyzer->analyze('SELECT * FROM users WHERE id = 1', $plan, [], [], null, $drift);

        $stability = $result['stability'];
        // drift contribution: round(0.8 * 30) = 24
        $this->assertGreaterThanOrEqual(24, $stability['volatility_score']);
    }

    // ---------------------------------------------------------------
    // 5. Volatile plan generates Warning finding
    // ---------------------------------------------------------------

    public function test_volatile_plan_generates_warning(): void
    {
        // Multiple large deviations to push score >= 60
        // Deviation 1: factor=100 → 25, Deviation 2: factor=50 → 25 → total 50
        // Plus drift contribution: round(0.5 * 30) = 15 → total 65
        $plan = "-> Nested loop inner join (cost=100.00 rows=1000) (actual time=0.10..1.00 rows=10 loops=1)\n"
            ."  -> Index lookup on users (cost=10.00 rows=500) (actual time=0.01..0.05 rows=10 loops=1)\n";

        $drift = [
            'composite_drift_score' => 0.5,
            'per_table' => [
                'users' => ['drift_ratio' => 0.6, 'drift_direction' => 'under'],
            ],
        ];

        $result = $this->analyzer->analyze('SELECT * FROM users', $plan, [], [], null, $drift);

        $stability = $result['stability'];
        $this->assertGreaterThanOrEqual(60, $stability['volatility_score']);
        $this->assertSame('volatile', $stability['volatility_label']);

        $volatilityFindings = array_filter(
            $result['findings'],
            fn (Finding $f) => str_contains($f->title, 'High plan volatility')
        );
        $this->assertNotEmpty($volatilityFindings);

        $finding = array_values($volatilityFindings)[0];
        $this->assertSame(Severity::Warning, $finding->severity);
        $this->assertSame('plan_stability', $finding->category);
        $this->assertArrayHasKey('volatility_score', $finding->metadata);
        $this->assertArrayHasKey('drift_contributors', $finding->metadata);
    }

    // ---------------------------------------------------------------
    // 6. Moderate volatility generates Optimization finding
    // ---------------------------------------------------------------

    public function test_moderate_volatility_generates_optimization(): void
    {
        // One large deviation: factor=100 → 25, plus drift: round(0.2 * 30)=6 → total 31
        $plan = "-> Index lookup on orders (cost=50.00 rows=500) (actual time=0.10..0.50 rows=5 loops=1)\n";

        $drift = [
            'composite_drift_score' => 0.2,
            'per_table' => [],
        ];

        $result = $this->analyzer->analyze('SELECT * FROM orders', $plan, [], [], null, $drift);

        $stability = $result['stability'];
        $this->assertGreaterThanOrEqual(30, $stability['volatility_score']);
        $this->assertLessThan(60, $stability['volatility_score']);
        $this->assertSame('moderate', $stability['volatility_label']);

        $moderateFindings = array_filter(
            $result['findings'],
            fn (Finding $f) => str_contains($f->title, 'Moderate plan volatility')
        );
        $this->assertNotEmpty($moderateFindings);

        $finding = array_values($moderateFindings)[0];
        $this->assertSame(Severity::Optimization, $finding->severity);
        $this->assertArrayHasKey('volatility_score', $finding->metadata);
    }

    // ---------------------------------------------------------------
    // 7. Stable plan - no volatility finding
    // ---------------------------------------------------------------

    public function test_stable_plan_no_volatility_finding(): void
    {
        // No deviations, no drift
        $plan = "-> Index lookup on users using PRIMARY (cost=1.00 rows=10) (actual time=0.01..0.02 rows=10 loops=1)\n";

        $result = $this->analyzer->analyze('SELECT * FROM users WHERE id = 1', $plan, [], [], null, null);

        $stability = $result['stability'];
        $this->assertLessThan(30, $stability['volatility_score']);
        $this->assertSame('stable', $stability['volatility_label']);

        $volatilityFindings = array_filter(
            $result['findings'],
            fn (Finding $f) => str_contains($f->title, 'volatility')
        );
        $this->assertEmpty($volatilityFindings);
    }

    // ---------------------------------------------------------------
    // 8. Drift contributors extracted
    // ---------------------------------------------------------------

    public function test_drift_contributors_extracted(): void
    {
        $plan = "-> Index lookup on users using PRIMARY (cost=1.00 rows=10) (actual time=0.01..0.02 rows=10 loops=1)\n";

        $drift = [
            'composite_drift_score' => 0.6,
            'per_table' => [
                'users' => ['drift_ratio' => 0.8, 'drift_direction' => 'under'],
                'orders' => ['drift_ratio' => 0.3, 'drift_direction' => 'over'],
                'payments' => ['drift_ratio' => 0.7, 'drift_direction' => 'under'],
            ],
        ];

        $result = $this->analyzer->analyze('SELECT * FROM users', $plan, [], [], null, $drift);

        $contributors = $result['stability']['drift_contributors'];
        $this->assertContains('users', $contributors);
        $this->assertContains('payments', $contributors);
        $this->assertNotContains('orders', $contributors, 'orders drift_ratio 0.3 is not > 0.5');
        $this->assertCount(2, $contributors);
    }

    // ---------------------------------------------------------------
    // 9. Drift contributors empty when no drift data
    // ---------------------------------------------------------------

    public function test_drift_contributors_empty_when_no_drift(): void
    {
        $plan = "-> Index lookup on users using PRIMARY (cost=1.00 rows=10) (actual time=0.01..0.02 rows=10 loops=1)\n";

        $result = $this->analyzer->analyze('SELECT * FROM users WHERE id = 1', $plan, [], [], null, null);

        $this->assertSame([], $result['stability']['drift_contributors']);
    }

    // ---------------------------------------------------------------
    // 10. Volatility clamped to 0-100
    // ---------------------------------------------------------------

    public function test_volatility_clamped_to_0_100(): void
    {
        // Extreme high: 4 large deviations (4 * 25 = 100) + high drift (round(1.0 * 30) = 30) = 130 → clamped to 100
        $plan = "-> Nested loop (cost=100.00 rows=10000) (actual time=0.10..1.00 rows=1 loops=1)\n"
            ."  -> Index scan (cost=50.00 rows=5000) (actual time=0.01..0.05 rows=1 loops=1)\n"
            ."  -> Table scan (cost=25.00 rows=2500) (actual time=0.01..0.03 rows=1 loops=1)\n"
            ."  -> Filter (cost=12.00 rows=1200) (actual time=0.01..0.02 rows=1 loops=1)\n";

        $drift = [
            'composite_drift_score' => 1.0,
            'per_table' => [],
        ];

        $resultHigh = $this->analyzer->analyze('SELECT 1', $plan, [], [], null, $drift);
        $this->assertSame(100, $resultHigh['stability']['volatility_score']);

        // Extreme low: hint reduces by 20, no deviations, no drift → -20 → clamped to 0
        $planClean = "-> Index lookup (cost=1.00 rows=10) (actual time=0.01..0.02 rows=10 loops=1)\n";
        $resultLow = $this->analyzer->analyze(
            'SELECT * FROM users FORCE INDEX (PRIMARY) WHERE id = 1',
            $planClean,
            [],
            [],
            null,
            null,
        );
        $this->assertSame(0, $resultLow['stability']['volatility_score']);
    }

    // ---------------------------------------------------------------
    // 11. Null cardinality drift works without errors
    // ---------------------------------------------------------------

    public function test_null_cardinality_drift_works(): void
    {
        $plan = "-> Table scan on orders (cost=100.00 rows=500) (actual time=0.10..1.00 rows=5 loops=1)\n";

        // Should not throw any exception with null drift
        $result = $this->analyzer->analyze('SELECT * FROM orders', $plan, [], [], null, null);

        $this->assertArrayHasKey('volatility_score', $result['stability']);
        $this->assertArrayHasKey('volatility_label', $result['stability']);
        $this->assertArrayHasKey('drift_contributors', $result['stability']);
        $this->assertSame([], $result['stability']['drift_contributors']);
        $this->assertIsInt($result['stability']['volatility_score']);
        $this->assertIsString($result['stability']['volatility_label']);
    }

    // ---------------------------------------------------------------
    // 12. Combined deviations and drift contribute to volatility
    // ---------------------------------------------------------------

    public function test_combined_deviations_and_drift(): void
    {
        // Deviation: estimated=100, actual=1 → factor=100 → contributes min(100*5, 25)=25
        $plan = "-> Index lookup on users (cost=10.00 rows=100) (actual time=0.10..0.50 rows=1 loops=1)\n";

        // Without drift
        $resultNoDrift = $this->analyzer->analyze('SELECT * FROM users', $plan, [], [], null, null);

        // With drift (composite_drift_score = 0.5 → adds round(0.5 * 30) = 15)
        $drift = [
            'composite_drift_score' => 0.5,
            'per_table' => [
                'users' => ['drift_ratio' => 0.6, 'drift_direction' => 'under'],
            ],
        ];
        $resultWithDrift = $this->analyzer->analyze('SELECT * FROM users', $plan, [], [], null, $drift);

        // Without drift: 25 (deviation only)
        // With drift: 25 + 15 = 40
        $this->assertSame(25, $resultNoDrift['stability']['volatility_score']);
        $this->assertSame(40, $resultWithDrift['stability']['volatility_score']);

        // Without drift: 25 < 30 → stable
        $this->assertSame('stable', $resultNoDrift['stability']['volatility_label']);
        // With drift: 40 >= 30 → moderate
        $this->assertSame('moderate', $resultWithDrift['stability']['volatility_label']);
    }

    // ---------------------------------------------------------------
    // 13. Drift contributors with exact threshold boundary
    // ---------------------------------------------------------------

    public function test_drift_contributors_boundary_at_0_5(): void
    {
        $plan = "-> Index lookup (cost=1.00 rows=10) (actual time=0.01..0.02 rows=10 loops=1)\n";

        $drift = [
            'composite_drift_score' => 0.5,
            'per_table' => [
                'exact_boundary' => ['drift_ratio' => 0.5, 'drift_direction' => 'over'],
                'above_boundary' => ['drift_ratio' => 0.51, 'drift_direction' => 'under'],
            ],
        ];

        $result = $this->analyzer->analyze('SELECT 1', $plan, [], [], null, $drift);

        $contributors = $result['stability']['drift_contributors'];
        // drift_ratio > 0.5 (strict), so 0.5 is NOT included
        $this->assertNotContains('exact_boundary', $contributors);
        $this->assertContains('above_boundary', $contributors);
    }

    // ---------------------------------------------------------------
    // 14. Multiple deviations accumulate volatility
    // ---------------------------------------------------------------

    public function test_multiple_deviations_accumulate_volatility(): void
    {
        // Two nodes with large deviations: each contributes 25 (capped) → total 50
        $plan = "-> Nested loop inner join (cost=100.00 rows=1000) (actual time=0.10..1.00 rows=5 loops=1)\n"
            ."  -> Index lookup on users (cost=10.00 rows=500) (actual time=0.01..0.05 rows=2 loops=1)\n";

        $result = $this->analyzer->analyze('SELECT * FROM users', $plan, [], [], null, null);

        $stability = $result['stability'];
        // Both deviations contribute 25 each → 50 total
        $this->assertSame(50, $stability['volatility_score']);
        $this->assertSame('moderate', $stability['volatility_label']);
    }

    // ---------------------------------------------------------------
    // 15. Volatility metadata in findings is correct
    // ---------------------------------------------------------------

    public function test_volatility_finding_metadata_is_correct(): void
    {
        // Push to volatile: 3 deviations (25*3=75) → volatile
        $plan = "-> Nested loop (cost=100.00 rows=1000) (actual time=0.10..1.00 rows=5 loops=1)\n"
            ."  -> Index scan (cost=50.00 rows=500) (actual time=0.01..0.05 rows=2 loops=1)\n"
            ."  -> Table scan (cost=25.00 rows=250) (actual time=0.01..0.03 rows=1 loops=1)\n";

        $drift = [
            'composite_drift_score' => 0.3,
            'per_table' => [
                'orders' => ['drift_ratio' => 0.9, 'drift_direction' => 'under'],
            ],
        ];

        $result = $this->analyzer->analyze('SELECT * FROM orders', $plan, [], [], null, $drift);

        $volatilityFindings = array_values(array_filter(
            $result['findings'],
            fn (Finding $f) => str_contains($f->title, 'High plan volatility')
        ));

        $this->assertNotEmpty($volatilityFindings);
        $finding = $volatilityFindings[0];

        $this->assertSame($result['stability']['volatility_score'], $finding->metadata['volatility_score']);
        $this->assertSame(['orders'], $finding->metadata['drift_contributors']);
        $this->assertStringContainsString('ANALYZE TABLE', $finding->recommendation);
    }
}
