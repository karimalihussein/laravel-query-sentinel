<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QuerySentinel\Analyzers\CardinalityDriftAnalyzer;
use QuerySentinel\Enums\Severity;

final class CardinalityDriftAnalyzerTest extends TestCase
{
    private CardinalityDriftAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new CardinalityDriftAnalyzer(warningThreshold: 0.5, criticalThreshold: 0.9);
    }

    // ---------------------------------------------------------------
    // 1. Accurate estimation - no drift
    // ---------------------------------------------------------------

    public function test_accurate_estimation_no_drift(): void
    {
        $metrics = [
            'per_table_estimates' => [
                'users' => [
                    'estimated_rows' => 100,
                    'actual_rows' => 100,
                    'loops' => 1,
                ],
            ],
        ];

        $result = $this->analyzer->analyze($metrics, []);

        $perTable = $result['cardinality_drift']['per_table'];
        $this->assertArrayHasKey('users', $perTable);
        $this->assertSame(0.0, $perTable['users']['drift_ratio']);
        $this->assertSame('accurate', $perTable['users']['drift_direction']);
        $this->assertSame('info', $perTable['users']['severity']);
        $this->assertEmpty($result['findings']);
    }

    // ---------------------------------------------------------------
    // 2. Moderate over-estimation
    // ---------------------------------------------------------------

    public function test_moderate_over_estimation(): void
    {
        // estimated=250, actual=100 → drift = 150/250 = 0.6 (above 0.5 warning threshold)
        $metrics = [
            'per_table_estimates' => [
                'orders' => [
                    'estimated_rows' => 250,
                    'actual_rows' => 100,
                    'loops' => 1,
                ],
            ],
        ];

        $result = $this->analyzer->analyze($metrics, []);

        $perTable = $result['cardinality_drift']['per_table'];
        $this->assertArrayHasKey('orders', $perTable);
        // drift = abs(250 - 100) / max(250, 100, 1) = 150 / 250 = 0.6
        $this->assertSame(0.6, $perTable['orders']['drift_ratio']);
        $this->assertSame('over', $perTable['orders']['drift_direction']);
        $this->assertSame('warning', $perTable['orders']['severity']);
    }

    // ---------------------------------------------------------------
    // 3. Severe under-estimation
    // ---------------------------------------------------------------

    public function test_severe_under_estimation(): void
    {
        $metrics = [
            'per_table_estimates' => [
                'logs' => [
                    'estimated_rows' => 10,
                    'actual_rows' => 10000,
                    'loops' => 1,
                ],
            ],
        ];

        $result = $this->analyzer->analyze($metrics, []);

        $perTable = $result['cardinality_drift']['per_table'];
        // drift = abs(10 - 10000) / max(10, 10000, 1) = 9990 / 10000 = 0.999
        $this->assertSame(0.999, $perTable['logs']['drift_ratio']);
        $this->assertSame('under', $perTable['logs']['drift_direction']);
        $this->assertSame('critical', $perTable['logs']['severity']);

        // Should produce a critical finding
        $this->assertCount(1, $result['findings']);
        $this->assertSame(Severity::Critical, $result['findings'][0]->severity);
    }

    // ---------------------------------------------------------------
    // 4. Critical drift threshold
    // ---------------------------------------------------------------

    public function test_critical_drift_threshold(): void
    {
        // Need drift > 0.9 → critical
        // estimated=5, actual=1000 → drift = 995/1000 = 0.995
        $metrics = [
            'per_table_estimates' => [
                'sessions' => [
                    'estimated_rows' => 5,
                    'actual_rows' => 1000,
                    'loops' => 1,
                ],
            ],
        ];

        $result = $this->analyzer->analyze($metrics, []);

        $perTable = $result['cardinality_drift']['per_table'];
        $this->assertGreaterThan(0.9, $perTable['sessions']['drift_ratio']);
        $this->assertSame('critical', $perTable['sessions']['severity']);

        $this->assertCount(1, $result['findings']);
        $this->assertSame(Severity::Critical, $result['findings'][0]->severity);
        $this->assertSame('cardinality_drift', $result['findings'][0]->category);
    }

    // ---------------------------------------------------------------
    // 5. Warning drift threshold
    // ---------------------------------------------------------------

    public function test_warning_drift_threshold(): void
    {
        // Need drift > 0.5 but <= 0.9 → warning
        // estimated=300, actual=100 → drift = 200/300 = 0.6667
        $metrics = [
            'per_table_estimates' => [
                'products' => [
                    'estimated_rows' => 300,
                    'actual_rows' => 100,
                    'loops' => 1,
                ],
            ],
        ];

        $result = $this->analyzer->analyze($metrics, []);

        $perTable = $result['cardinality_drift']['per_table'];
        $drift = $perTable['products']['drift_ratio'];
        $this->assertGreaterThan(0.5, $drift);
        $this->assertLessThanOrEqual(0.9, $drift);
        $this->assertSame('warning', $perTable['products']['severity']);

        $this->assertCount(1, $result['findings']);
        $this->assertSame(Severity::Warning, $result['findings'][0]->severity);
    }

    // ---------------------------------------------------------------
    // 6. Optimization drift threshold (no finding generated)
    // ---------------------------------------------------------------

    public function test_optimization_drift_threshold(): void
    {
        // Need drift > 0.2 but <= 0.5 → optimization severity, no finding
        // estimated=150, actual=100 → drift = 50/150 = 0.3333
        $metrics = [
            'per_table_estimates' => [
                'categories' => [
                    'estimated_rows' => 150,
                    'actual_rows' => 100,
                    'loops' => 1,
                ],
            ],
        ];

        $result = $this->analyzer->analyze($metrics, []);

        $perTable = $result['cardinality_drift']['per_table'];
        $drift = $perTable['categories']['drift_ratio'];
        $this->assertGreaterThan(0.2, $drift);
        $this->assertLessThanOrEqual(0.5, $drift);
        $this->assertSame('optimization', $perTable['categories']['severity']);

        // No finding generated because drift <= warning threshold (0.5)
        $this->assertEmpty($result['findings']);
    }

    // ---------------------------------------------------------------
    // 7. Multiple tables - composite score
    // ---------------------------------------------------------------

    public function test_multiple_tables_composite_score(): void
    {
        $metrics = [
            'per_table_estimates' => [
                'users' => [
                    'estimated_rows' => 100,
                    'actual_rows' => 100,
                    'loops' => 1,
                ],
                'orders' => [
                    'estimated_rows' => 10,
                    'actual_rows' => 10000,
                    'loops' => 1,
                ],
            ],
        ];

        $result = $this->analyzer->analyze($metrics, []);

        $composite = $result['cardinality_drift']['composite_drift_score'];
        // users: drift=0, actual=100
        // orders: drift=0.999, actual=10000
        // weighted = (0 * 100 + 0.999 * 10000) / (100 + 10000) = 9990 / 10100 ≈ 0.9891
        $this->assertGreaterThan(0.0, $composite);
        $this->assertLessThanOrEqual(1.0, $composite);

        // Two tables in per_table
        $this->assertCount(2, $result['cardinality_drift']['per_table']);
    }

    // ---------------------------------------------------------------
    // 8. Zero rows - no division error
    // ---------------------------------------------------------------

    public function test_zero_rows_no_division_error(): void
    {
        $metrics = [
            'per_table_estimates' => [
                'empty_table' => [
                    'estimated_rows' => 0,
                    'actual_rows' => 0,
                    'loops' => 1,
                ],
            ],
        ];

        $result = $this->analyzer->analyze($metrics, []);

        $perTable = $result['cardinality_drift']['per_table'];
        $this->assertArrayHasKey('empty_table', $perTable);
        // denominator = max(0, 0, 1) = 1, drift = 0/1 = 0
        $this->assertSame(0.0, $perTable['empty_table']['drift_ratio']);
        $this->assertSame('accurate', $perTable['empty_table']['drift_direction']);
        $this->assertEmpty($result['findings']);
    }

    // ---------------------------------------------------------------
    // 9. Tables needing ANALYZE populated
    // ---------------------------------------------------------------

    public function test_tables_needing_analyze_populated(): void
    {
        $metrics = [
            'per_table_estimates' => [
                'users' => [
                    'estimated_rows' => 100,
                    'actual_rows' => 100,
                    'loops' => 1,
                ],
                'orders' => [
                    'estimated_rows' => 10,
                    'actual_rows' => 10000,
                    'loops' => 1,
                ],
                'products' => [
                    'estimated_rows' => 300,
                    'actual_rows' => 100,
                    'loops' => 1,
                ],
            ],
        ];

        $result = $this->analyzer->analyze($metrics, []);

        $tablesNeedingAnalyze = $result['cardinality_drift']['tables_needing_analyze'];
        // users: drift=0 (below threshold)
        // orders: drift=0.999 (above threshold)
        // products: drift=0.6667 (above threshold)
        $this->assertContains('orders', $tablesNeedingAnalyze);
        $this->assertContains('products', $tablesNeedingAnalyze);
        $this->assertNotContains('users', $tablesNeedingAnalyze);
    }

    // ---------------------------------------------------------------
    // 10. Loops multiplied into estimates
    // ---------------------------------------------------------------

    public function test_loops_multiplied_into_estimates(): void
    {
        $metrics = [
            'per_table_estimates' => [
                'order_items' => [
                    'estimated_rows' => 50,
                    'actual_rows' => 10,
                    'loops' => 5,
                ],
            ],
        ];

        $result = $this->analyzer->analyze($metrics, []);

        $perTable = $result['cardinality_drift']['per_table'];
        // totalEstimated = 50 * 5 = 250, totalActual = 10 * 5 = 50
        $this->assertSame(250, $perTable['order_items']['estimated_rows']);
        $this->assertSame(50, $perTable['order_items']['actual_rows']);

        // drift = abs(250 - 50) / max(250, 50, 1) = 200 / 250 = 0.8
        $this->assertSame(0.8, $perTable['order_items']['drift_ratio']);
        $this->assertSame('over', $perTable['order_items']['drift_direction']);
    }

    // ---------------------------------------------------------------
    // 11. Empty per_table_estimates
    // ---------------------------------------------------------------

    public function test_empty_per_table_estimates(): void
    {
        $metrics = [
            'per_table_estimates' => [],
        ];

        $result = $this->analyzer->analyze($metrics, []);

        $this->assertEmpty($result['cardinality_drift']['per_table']);
        $this->assertEmpty($result['cardinality_drift']['tables_needing_analyze']);
        $this->assertSame(0.0, $result['cardinality_drift']['composite_drift_score']);
        $this->assertEmpty($result['findings']);
    }

    // ---------------------------------------------------------------
    // 12. Single table - perfect match
    // ---------------------------------------------------------------

    public function test_single_table_perfect_match(): void
    {
        $metrics = [
            'per_table_estimates' => [
                'users' => [
                    'estimated_rows' => 500,
                    'actual_rows' => 500,
                    'loops' => 1,
                ],
            ],
        ];

        $result = $this->analyzer->analyze($metrics, []);

        $perTable = $result['cardinality_drift']['per_table'];
        $this->assertSame('accurate', $perTable['users']['drift_direction']);
        $this->assertSame(0.0, $perTable['users']['drift_ratio']);
        $this->assertSame(0.0, $result['cardinality_drift']['composite_drift_score']);
    }

    // ---------------------------------------------------------------
    // 13. Composite drift weighted by actual rows
    // ---------------------------------------------------------------

    public function test_composite_drift_weighted_by_actual_rows(): void
    {
        $metrics = [
            'per_table_estimates' => [
                // Small table, high drift
                'small_table' => [
                    'estimated_rows' => 1,
                    'actual_rows' => 10,
                    'loops' => 1,
                ],
                // Large table, zero drift
                'large_table' => [
                    'estimated_rows' => 10000,
                    'actual_rows' => 10000,
                    'loops' => 1,
                ],
            ],
        ];

        $result = $this->analyzer->analyze($metrics, []);

        $composite = $result['cardinality_drift']['composite_drift_score'];

        // small_table: drift=0.9, actual=10
        // large_table: drift=0.0, actual=10000
        // weighted = (0.9 * 10 + 0.0 * 10000) / (10 + 10000) = 9 / 10010 ≈ 0.0009
        // The large table dominates, keeping the composite low
        $this->assertLessThan(0.01, $composite);

        // Now compare with opposite: if large table had the drift
        $metrics2 = [
            'per_table_estimates' => [
                'small_table' => [
                    'estimated_rows' => 10,
                    'actual_rows' => 10,
                    'loops' => 1,
                ],
                'large_table' => [
                    'estimated_rows' => 1,
                    'actual_rows' => 10000,
                    'loops' => 1,
                ],
            ],
        ];

        $result2 = $this->analyzer->analyze($metrics2, []);
        $composite2 = $result2['cardinality_drift']['composite_drift_score'];

        // large_table dominates with high drift, so composite should be much higher
        $this->assertGreaterThan($composite, $composite2);
    }

    // ---------------------------------------------------------------
    // 14. Finding metadata contains details
    // ---------------------------------------------------------------

    public function test_finding_metadata_contains_details(): void
    {
        $metrics = [
            'per_table_estimates' => [
                'payments' => [
                    'estimated_rows' => 5,
                    'actual_rows' => 5000,
                    'loops' => 1,
                ],
            ],
        ];

        $result = $this->analyzer->analyze($metrics, []);

        $this->assertCount(1, $result['findings']);
        $finding = $result['findings'][0];

        $this->assertArrayHasKey('table', $finding->metadata);
        $this->assertArrayHasKey('estimated', $finding->metadata);
        $this->assertArrayHasKey('actual', $finding->metadata);
        $this->assertArrayHasKey('drift_ratio', $finding->metadata);
        $this->assertArrayHasKey('direction', $finding->metadata);

        $this->assertSame('payments', $finding->metadata['table']);
        $this->assertSame(5, $finding->metadata['estimated']);
        $this->assertSame(5000, $finding->metadata['actual']);
        $this->assertSame('under', $finding->metadata['direction']);
        $this->assertGreaterThan(0.9, $finding->metadata['drift_ratio']);
    }

    // ---------------------------------------------------------------
    // 15. Finding recommendation is ANALYZE TABLE
    // ---------------------------------------------------------------

    public function test_finding_recommendation_is_analyze_table(): void
    {
        $metrics = [
            'per_table_estimates' => [
                'invoices' => [
                    'estimated_rows' => 10,
                    'actual_rows' => 10000,
                    'loops' => 1,
                ],
            ],
        ];

        $result = $this->analyzer->analyze($metrics, []);

        $this->assertCount(1, $result['findings']);
        $finding = $result['findings'][0];

        $this->assertNotNull($finding->recommendation);
        $this->assertStringContainsString('ANALYZE TABLE', $finding->recommendation);
        $this->assertStringContainsString('invoices', $finding->recommendation);
    }

    // ---------------------------------------------------------------
    // 16. Custom thresholds respected
    // ---------------------------------------------------------------

    public function test_custom_thresholds(): void
    {
        $customAnalyzer = new CardinalityDriftAnalyzer(warningThreshold: 0.3, criticalThreshold: 0.7);

        // drift=0.35 → should be warning with custom thresholds (> 0.3) but NOT with default (> 0.5)
        // estimated=160, actual=100 → drift = 60/160 = 0.375
        $metrics = [
            'per_table_estimates' => [
                'items' => [
                    'estimated_rows' => 160,
                    'actual_rows' => 100,
                    'loops' => 1,
                ],
            ],
        ];

        $resultCustom = $customAnalyzer->analyze($metrics, []);
        $resultDefault = $this->analyzer->analyze($metrics, []);

        // Custom: drift 0.375 > 0.3 warning threshold → produces a finding
        $this->assertCount(1, $resultCustom['findings']);
        $this->assertSame(Severity::Warning, $resultCustom['findings'][0]->severity);

        // Default: drift 0.375 < 0.5 warning threshold → no finding
        $this->assertEmpty($resultDefault['findings']);

        // Now test critical with custom threshold
        // estimated=10, actual=100 → drift = 90/100 = 0.9
        $metricsHighDrift = [
            'per_table_estimates' => [
                'events' => [
                    'estimated_rows' => 10,
                    'actual_rows' => 100,
                    'loops' => 1,
                ],
            ],
        ];

        $resultCustomHigh = $customAnalyzer->analyze($metricsHighDrift, []);
        $resultDefaultHigh = $this->analyzer->analyze($metricsHighDrift, []);

        // Custom: drift 0.9 > 0.7 critical threshold → critical finding
        $this->assertSame(Severity::Critical, $resultCustomHigh['findings'][0]->severity);

        // Default: drift 0.9 = 0.9 (not > 0.9) → warning finding
        $this->assertSame(Severity::Warning, $resultDefaultHigh['findings'][0]->severity);
    }
}
