<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QuerySentinel\Enums\Severity;
use QuerySentinel\Scoring\ConfidenceScorer;
use QuerySentinel\Support\EnvironmentContext;
use QuerySentinel\Support\Finding;

final class ConfidenceScorerTest extends TestCase
{
    private ConfidenceScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scorer = new ConfidenceScorer;
    }

    // ---------------------------------------------------------------
    // Helper to build a warm EnvironmentContext
    // ---------------------------------------------------------------

    private function makeEnvironment(bool $isColdCache = false): EnvironmentContext
    {
        return new EnvironmentContext(
            mysqlVersion: '8.0.35',
            bufferPoolSizeBytes: 134217728,
            innodbIoCapacity: 200,
            innodbPageSize: 16384,
            tmpTableSize: 16777216,
            maxHeapTableSize: 16777216,
            bufferPoolUtilization: 0.85,
            isColdCache: $isColdCache,
            databaseName: 'test',
        );
    }

    /**
     * Build metrics with sensible defaults for optimal scoring.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function makeOptimalMetrics(array $overrides = []): array
    {
        return array_merge([
            'per_table_estimates' => [
                'users' => [
                    'estimated_rows' => 5000,
                    'actual_rows' => 5000,
                    'loops' => 1,
                ],
            ],
            'tables_accessed' => ['users'],
            'join_count' => 1,
        ], $overrides);
    }

    /**
     * Build cardinality drift data with sensible defaults for optimal scoring.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function makeOptimalDrift(array $overrides = []): array
    {
        return array_merge([
            'composite_drift_score' => 0.0,
            'tables_needing_analyze' => [],
        ], $overrides);
    }

    /**
     * Build stability analysis with sensible defaults for optimal scoring.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function makeOptimalStability(array $overrides = []): array
    {
        return array_merge([
            'plan_flip_risk' => ['is_risky' => false],
        ], $overrides);
    }

    // ---------------------------------------------------------------
    // 1. Perfect confidence - all factors optimal
    // ---------------------------------------------------------------

    public function test_perfect_confidence_all_factors_optimal(): void
    {
        $metrics = $this->makeOptimalMetrics();
        $drift = $this->makeOptimalDrift();
        $stability = $this->makeOptimalStability();
        $environment = $this->makeEnvironment(isColdCache: false);

        $result = $this->scorer->score($metrics, $drift, $stability, $environment, true);

        $this->assertGreaterThanOrEqual(0.9, $result['confidence']['overall']);
        $this->assertSame('high', $result['confidence']['label']);
    }

    // ---------------------------------------------------------------
    // 2. No EXPLAIN ANALYZE reduces confidence
    // ---------------------------------------------------------------

    public function test_no_explain_analyze_reduces_confidence(): void
    {
        $metrics = $this->makeOptimalMetrics();
        $drift = $this->makeOptimalDrift();
        $stability = $this->makeOptimalStability();
        $environment = $this->makeEnvironment(isColdCache: false);

        $withAnalyze = $this->scorer->score($metrics, $drift, $stability, $environment, true);
        $withoutAnalyze = $this->scorer->score($metrics, $drift, $stability, $environment, false);

        $this->assertLessThan($withAnalyze['confidence']['overall'], $withoutAnalyze['confidence']['overall']);

        // The explain_analyze factor should be 0.3 when not available
        $analyzeFactors = array_filter($withoutAnalyze['confidence']['factors'], fn ($f) => $f['name'] === 'explain_analyze');
        $analyzeFactor = array_values($analyzeFactors)[0];
        $this->assertSame(0.3, $analyzeFactor['score']);
    }

    // ---------------------------------------------------------------
    // 3. Cold cache reduces confidence
    // ---------------------------------------------------------------

    public function test_cold_cache_reduces_confidence(): void
    {
        $metrics = $this->makeOptimalMetrics();
        $drift = $this->makeOptimalDrift();
        $stability = $this->makeOptimalStability();

        $warmEnv = $this->makeEnvironment(isColdCache: false);
        $coldEnv = $this->makeEnvironment(isColdCache: true);

        $warmResult = $this->scorer->score($metrics, $drift, $stability, $warmEnv, true);
        $coldResult = $this->scorer->score($metrics, $drift, $stability, $coldEnv, true);

        $this->assertLessThan($warmResult['confidence']['overall'], $coldResult['confidence']['overall']);

        // The cache_warmth factor should be 0.5 when cold
        $cacheFactor = array_values(array_filter(
            $coldResult['confidence']['factors'],
            fn ($f) => $f['name'] === 'cache_warmth'
        ))[0];
        $this->assertSame(0.5, $cacheFactor['score']);
    }

    // ---------------------------------------------------------------
    // 4. High drift reduces estimation accuracy
    // ---------------------------------------------------------------

    public function test_high_drift_reduces_estimation_accuracy(): void
    {
        $metrics = $this->makeOptimalMetrics();
        $drift = $this->makeOptimalDrift(['composite_drift_score' => 0.8]);
        $stability = $this->makeOptimalStability();
        $environment = $this->makeEnvironment(isColdCache: false);

        $result = $this->scorer->score($metrics, $drift, $stability, $environment, true);

        // estimation_accuracy = max(0, 1.0 - 0.8) = 0.2
        $estimationFactor = array_values(array_filter(
            $result['confidence']['factors'],
            fn ($f) => $f['name'] === 'estimation_accuracy'
        ))[0];
        $this->assertSame(0.2, $estimationFactor['score']);
        $this->assertLessThan(0.5, $estimationFactor['score']);
        $this->assertSame('High drift between estimated and actual rows', $estimationFactor['note']);
    }

    // ---------------------------------------------------------------
    // 5. Small sample reduces confidence
    // ---------------------------------------------------------------

    public function test_small_sample_reduces_confidence(): void
    {
        $metrics = $this->makeOptimalMetrics([
            'per_table_estimates' => [
                'users' => [
                    'estimated_rows' => 5,
                    'actual_rows' => 5,
                    'loops' => 1,
                ],
            ],
        ]);
        $drift = $this->makeOptimalDrift();
        $stability = $this->makeOptimalStability();
        $environment = $this->makeEnvironment(isColdCache: false);

        $result = $this->scorer->score($metrics, $drift, $stability, $environment, true);

        // sample_size = min(5 / 1000, 1.0) = 0.005
        $sampleFactor = array_values(array_filter(
            $result['confidence']['factors'],
            fn ($f) => $f['name'] === 'sample_size'
        ))[0];
        $this->assertLessThan(0.1, $sampleFactor['score']);
        $this->assertStringContainsString('Very small sample', $sampleFactor['note']);
    }

    // ---------------------------------------------------------------
    // 6. Plan flip risk reduces stability
    // ---------------------------------------------------------------

    public function test_plan_flip_risk_reduces_stability(): void
    {
        $metrics = $this->makeOptimalMetrics();
        $drift = $this->makeOptimalDrift();
        $stability = $this->makeOptimalStability(['plan_flip_risk' => ['is_risky' => true]]);
        $environment = $this->makeEnvironment(isColdCache: false);

        $result = $this->scorer->score($metrics, $drift, $stability, $environment, true);

        $planFactor = array_values(array_filter(
            $result['confidence']['factors'],
            fn ($f) => $f['name'] === 'plan_stability'
        ))[0];
        $this->assertSame(0.5, $planFactor['score']);
        $this->assertStringContainsString('Plan flip risk detected', $planFactor['note']);
    }

    // ---------------------------------------------------------------
    // 7. Complex query reduces score
    // ---------------------------------------------------------------

    public function test_complex_query_reduces_score(): void
    {
        $metrics = $this->makeOptimalMetrics(['join_count' => 5]);
        $drift = $this->makeOptimalDrift();
        $stability = $this->makeOptimalStability();
        $environment = $this->makeEnvironment(isColdCache: false);

        $result = $this->scorer->score($metrics, $drift, $stability, $environment, true);

        $complexityFactor = array_values(array_filter(
            $result['confidence']['factors'],
            fn ($f) => $f['name'] === 'query_complexity'
        ))[0];
        $this->assertSame(0.7, $complexityFactor['score']);
        $this->assertStringContainsString('Complex query with 5 tables', $complexityFactor['note']);
    }

    // ---------------------------------------------------------------
    // 8. Unreliable label below 0.5
    // ---------------------------------------------------------------

    public function test_unreliable_label_below_0_5(): void
    {
        // Create worst-case scenario to push confidence below 0.5
        $metrics = [
            'per_table_estimates' => [
                'tiny' => [
                    'estimated_rows' => 1,
                    'actual_rows' => 1,
                    'loops' => 1,
                ],
            ],
            'tables_accessed' => ['tiny', 'other'],
            'join_count' => 5,
        ];
        $drift = [
            'composite_drift_score' => 1.0,
            'tables_needing_analyze' => ['tiny', 'other'],
        ];
        $stability = ['plan_flip_risk' => ['is_risky' => true]];

        // null environment => isColdCache defaults to true
        // supportsAnalyze=false => explain_analyze=0.3, driver_capabilities=0.6
        $result = $this->scorer->score($metrics, $drift, $stability, null, false);

        $this->assertLessThan(0.5, $result['confidence']['overall']);
        $this->assertSame('unreliable', $result['confidence']['label']);
    }

    // ---------------------------------------------------------------
    // 9. Low label between 0.5 and 0.7
    // ---------------------------------------------------------------

    public function test_low_label_between_0_5_and_0_7(): void
    {
        // Moderately degraded scenario to land between 0.5 and 0.7
        $metrics = [
            'per_table_estimates' => [
                'users' => [
                    'estimated_rows' => 500,
                    'actual_rows' => 500,
                    'loops' => 1,
                ],
            ],
            'tables_accessed' => ['users'],
            'join_count' => 1,
        ];
        // moderate drift
        $drift = [
            'composite_drift_score' => 0.5,
            'tables_needing_analyze' => [],
        ];
        $stability = ['plan_flip_risk' => ['is_risky' => true]];

        // null env => cold cache (0.5), supportsAnalyze=false => explain=0.3, driver=0.6
        // Factor scores:
        //   estimation_accuracy: max(0, 1 - 0.5) = 0.5, weight 0.25 => 0.125
        //   sample_size: min(500/1000, 1) = 0.5, weight 0.20 => 0.10
        //   explain_analyze: 0.3, weight 0.15 => 0.045
        //   cache_warmth: 0.5 (null env => cold), weight 0.10 => 0.05
        //   statistics_freshness: 1.0, weight 0.10 => 0.10
        //   plan_stability: 0.5, weight 0.10 => 0.05
        //   query_complexity: 1.0, weight 0.05 => 0.05
        //   driver_capabilities: 0.6, weight 0.05 => 0.03
        //   total = 0.125 + 0.10 + 0.045 + 0.05 + 0.10 + 0.05 + 0.05 + 0.03 = 0.55
        $result = $this->scorer->score($metrics, $drift, $stability, null, false);

        $overall = $result['confidence']['overall'];
        $this->assertGreaterThanOrEqual(0.5, $overall);
        $this->assertLessThan(0.7, $overall);
        $this->assertSame('low', $result['confidence']['label']);
    }

    // ---------------------------------------------------------------
    // 10. Moderate label between 0.7 and 0.9
    // ---------------------------------------------------------------

    public function test_moderate_label_between_0_7_and_0_9(): void
    {
        $metrics = $this->makeOptimalMetrics();
        $drift = $this->makeOptimalDrift();
        $stability = $this->makeOptimalStability();
        $environment = $this->makeEnvironment(isColdCache: false);

        // supportsAnalyze=false drops explain_analyze to 0.3 and driver to 0.6
        // Factor scores:
        //   estimation_accuracy: 1.0, weight 0.25 => 0.25
        //   sample_size: min(5000/1000, 1) = 1.0, weight 0.20 => 0.20
        //   explain_analyze: 0.3, weight 0.15 => 0.045
        //   cache_warmth: 1.0 (warm), weight 0.10 => 0.10
        //   statistics_freshness: 1.0, weight 0.10 => 0.10
        //   plan_stability: 1.0, weight 0.10 => 0.10
        //   query_complexity: 1.0, weight 0.05 => 0.05
        //   driver_capabilities: 0.6, weight 0.05 => 0.03
        //   total = 0.25 + 0.20 + 0.045 + 0.10 + 0.10 + 0.10 + 0.05 + 0.03 = 0.875
        $result = $this->scorer->score($metrics, $drift, $stability, $environment, false);

        $overall = $result['confidence']['overall'];
        $this->assertGreaterThanOrEqual(0.7, $overall);
        $this->assertLessThan(0.9, $overall);
        $this->assertSame('moderate', $result['confidence']['label']);
    }

    // ---------------------------------------------------------------
    // 11. High label above 0.9
    // ---------------------------------------------------------------

    public function test_high_label_above_0_9(): void
    {
        $metrics = $this->makeOptimalMetrics();
        $drift = $this->makeOptimalDrift();
        $stability = $this->makeOptimalStability();
        $environment = $this->makeEnvironment(isColdCache: false);

        $result = $this->scorer->score($metrics, $drift, $stability, $environment, true);

        $this->assertGreaterThanOrEqual(0.9, $result['confidence']['overall']);
        $this->assertSame('high', $result['confidence']['label']);
    }

    // ---------------------------------------------------------------
    // 12. Warning finding for low confidence (below 0.5)
    // ---------------------------------------------------------------

    public function test_warning_finding_for_low_confidence(): void
    {
        // Worst-case inputs to push overall below 0.5
        $metrics = [
            'per_table_estimates' => [
                'tiny' => [
                    'estimated_rows' => 1,
                    'actual_rows' => 1,
                    'loops' => 1,
                ],
            ],
            'tables_accessed' => ['tiny', 'other'],
            'join_count' => 5,
        ];
        $drift = [
            'composite_drift_score' => 1.0,
            'tables_needing_analyze' => ['tiny', 'other'],
        ];
        $stability = ['plan_flip_risk' => ['is_risky' => true]];

        $result = $this->scorer->score($metrics, $drift, $stability, null, false);

        $this->assertLessThan(0.5, $result['confidence']['overall']);
        $this->assertCount(1, $result['findings']);
        $this->assertSame(Severity::Warning, $result['findings'][0]->severity);
        $this->assertSame('confidence', $result['findings'][0]->category);
        $this->assertStringContainsString('Low analysis confidence', $result['findings'][0]->title);
        $this->assertNotNull($result['findings'][0]->recommendation);
    }

    // ---------------------------------------------------------------
    // 13. Optimization finding for moderate confidence (0.5 to 0.7)
    // ---------------------------------------------------------------

    public function test_optimization_finding_for_moderate(): void
    {
        $metrics = [
            'per_table_estimates' => [
                'users' => [
                    'estimated_rows' => 500,
                    'actual_rows' => 500,
                    'loops' => 1,
                ],
            ],
            'tables_accessed' => ['users'],
            'join_count' => 1,
        ];
        $drift = [
            'composite_drift_score' => 0.5,
            'tables_needing_analyze' => [],
        ];
        $stability = ['plan_flip_risk' => ['is_risky' => true]];

        $result = $this->scorer->score($metrics, $drift, $stability, null, false);

        $overall = $result['confidence']['overall'];
        $this->assertGreaterThanOrEqual(0.5, $overall);
        $this->assertLessThan(0.7, $overall);

        $this->assertCount(1, $result['findings']);
        $this->assertSame(Severity::Optimization, $result['findings'][0]->severity);
        $this->assertSame('confidence', $result['findings'][0]->category);
        $this->assertStringContainsString('Moderate analysis confidence', $result['findings'][0]->title);
    }

    // ---------------------------------------------------------------
    // 14. No finding for high confidence (>= 0.7)
    // ---------------------------------------------------------------

    public function test_no_finding_for_high_confidence(): void
    {
        $metrics = $this->makeOptimalMetrics();
        $drift = $this->makeOptimalDrift();
        $stability = $this->makeOptimalStability();
        $environment = $this->makeEnvironment(isColdCache: false);

        $result = $this->scorer->score($metrics, $drift, $stability, $environment, true);

        $this->assertGreaterThanOrEqual(0.7, $result['confidence']['overall']);
        $this->assertEmpty($result['findings']);
    }

    // ---------------------------------------------------------------
    // 15. Factors array always has 8 entries
    // ---------------------------------------------------------------

    public function test_factors_array_has_8_entries(): void
    {
        // Test with full inputs
        $result1 = $this->scorer->score(
            $this->makeOptimalMetrics(),
            $this->makeOptimalDrift(),
            $this->makeOptimalStability(),
            $this->makeEnvironment(),
            true,
        );
        $this->assertCount(8, $result1['confidence']['factors']);

        // Test with all null inputs
        $result2 = $this->scorer->score([], null, null, null, false);
        $this->assertCount(8, $result2['confidence']['factors']);

        // Verify each factor has the expected structure
        $expectedNames = [
            'estimation_accuracy',
            'sample_size',
            'explain_analyze',
            'cache_warmth',
            'statistics_freshness',
            'plan_stability',
            'query_complexity',
            'driver_capabilities',
        ];

        $factorNames = array_map(fn ($f) => $f['name'], $result1['confidence']['factors']);
        $this->assertSame($expectedNames, $factorNames);

        foreach ($result1['confidence']['factors'] as $factor) {
            $this->assertArrayHasKey('name', $factor);
            $this->assertArrayHasKey('score', $factor);
            $this->assertArrayHasKey('weight', $factor);
            $this->assertArrayHasKey('note', $factor);
            $this->assertIsFloat($factor['score']);
            $this->assertIsFloat($factor['weight']);
            $this->assertIsString($factor['note']);
        }
    }

    // ---------------------------------------------------------------
    // 16. Null inputs use defaults safely
    // ---------------------------------------------------------------

    public function test_null_inputs_use_defaults(): void
    {
        // All optional parameters are null, metrics is empty
        $result = $this->scorer->score([], null, null, null, false);

        $this->assertArrayHasKey('confidence', $result);
        $this->assertArrayHasKey('findings', $result);
        $this->assertArrayHasKey('overall', $result['confidence']);
        $this->assertArrayHasKey('label', $result['confidence']);
        $this->assertArrayHasKey('factors', $result['confidence']);

        // overall should be a float between 0 and 1
        $overall = $result['confidence']['overall'];
        $this->assertIsFloat($overall);
        $this->assertGreaterThanOrEqual(0.0, $overall);
        $this->assertLessThanOrEqual(1.0, $overall);

        // Verify defaults are applied:
        // estimation_accuracy: drift defaults to 0.0 => score = 1.0
        $factors = $result['confidence']['factors'];

        $estimation = array_values(array_filter($factors, fn ($f) => $f['name'] === 'estimation_accuracy'))[0];
        $this->assertSame(1.0, $estimation['score']);

        // sample_size: no per_table_estimates => 0 actual rows => score = 0.0
        $sample = array_values(array_filter($factors, fn ($f) => $f['name'] === 'sample_size'))[0];
        $this->assertSame(0.0, $sample['score']);

        // explain_analyze: supportsAnalyze=false => 0.3
        $analyze = array_values(array_filter($factors, fn ($f) => $f['name'] === 'explain_analyze'))[0];
        $this->assertSame(0.3, $analyze['score']);

        // cache_warmth: null env => isColdCache defaults to true => 0.5
        $cache = array_values(array_filter($factors, fn ($f) => $f['name'] === 'cache_warmth'))[0];
        $this->assertSame(0.5, $cache['score']);

        // statistics_freshness: no tables => staleRatio = 0.0 => score = 1.0
        $stats = array_values(array_filter($factors, fn ($f) => $f['name'] === 'statistics_freshness'))[0];
        $this->assertSame(1.0, $stats['score']);

        // plan_stability: null stability => is_risky defaults to false => 1.0
        $plan = array_values(array_filter($factors, fn ($f) => $f['name'] === 'plan_stability'))[0];
        $this->assertSame(1.0, $plan['score']);

        // query_complexity: join_count defaults to 0 => 1.0
        $complexity = array_values(array_filter($factors, fn ($f) => $f['name'] === 'query_complexity'))[0];
        $this->assertSame(1.0, $complexity['score']);

        // driver_capabilities: supportsAnalyze=false => 0.6
        $driver = array_values(array_filter($factors, fn ($f) => $f['name'] === 'driver_capabilities'))[0];
        $this->assertSame(0.6, $driver['score']);
    }
}
