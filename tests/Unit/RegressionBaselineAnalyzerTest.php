<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QuerySentinel\Analyzers\RegressionBaselineAnalyzer;
use QuerySentinel\Enums\Severity;
use QuerySentinel\Support\BaselineStore;
use QuerySentinel\Support\Finding;

final class RegressionBaselineAnalyzerTest extends TestCase
{
    private string $tempDir;

    private BaselineStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/qs_regression_test_'.uniqid();
        $this->store = new BaselineStore($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // 1. No baseline - has_baseline=false, no regressions, trend='stable'
    // ---------------------------------------------------------------

    public function test_no_baseline_returns_defaults(): void
    {
        $analyzer = new RegressionBaselineAnalyzer($this->store);

        $result = $analyzer->analyze('SELECT * FROM users', [
            'composite_score' => 80.0,
            'execution_time_ms' => 5.0,
            'rows_examined' => 100,
        ]);

        $regression = $result['regression'];
        $this->assertFalse($regression['has_baseline']);
        $this->assertSame(0, $regression['baseline_count']);
        $this->assertSame([], $regression['regressions']);
        $this->assertSame([], $regression['improvements']);
        $this->assertSame('stable', $regression['trend']);
        $this->assertSame([], $result['findings']);
    }

    // ---------------------------------------------------------------
    // 2. Score regression warning detected
    // ---------------------------------------------------------------

    public function test_score_regression_warning_detected(): void
    {
        $queryHash = hash('sha256', strtolower(trim('select * from users')));

        // Seed baseline: score = 80
        $this->store->save($queryHash, [
            'composite_score' => 80.0,
            'execution_time_ms' => 5.0,
            'rows_examined' => 100,
            'timestamp' => date('c'),
        ]);

        $analyzer = new RegressionBaselineAnalyzer($this->store);

        // Current score = 68 → regression = (80 - 68) / 80 * 100 = 15% → warning (>10, <25)
        $result = $analyzer->analyze('SELECT * FROM users', [
            'composite_score' => 68.0,
            'execution_time_ms' => 5.0,
            'rows_examined' => 100,
        ]);

        $regression = $result['regression'];
        $this->assertTrue($regression['has_baseline']);
        $this->assertNotEmpty($regression['regressions']);

        $scoreRegression = $this->findRegressionByMetric($regression['regressions'], 'composite_score');
        $this->assertNotNull($scoreRegression);
        $this->assertSame('warning', $scoreRegression['severity']);
    }

    // ---------------------------------------------------------------
    // 3. Score regression critical detected
    // ---------------------------------------------------------------

    public function test_score_regression_critical_detected(): void
    {
        $queryHash = hash('sha256', strtolower(trim('select * from orders')));

        $this->store->save($queryHash, [
            'composite_score' => 80.0,
            'execution_time_ms' => 5.0,
            'rows_examined' => 100,
            'timestamp' => date('c'),
        ]);

        $analyzer = new RegressionBaselineAnalyzer($this->store);

        // Current score = 50 → regression = (80 - 50) / 80 * 100 = 37.5% → critical (>25)
        $result = $analyzer->analyze('SELECT * FROM orders', [
            'composite_score' => 50.0,
            'execution_time_ms' => 5.0,
            'rows_examined' => 100,
        ]);

        $scoreRegression = $this->findRegressionByMetric($result['regression']['regressions'], 'composite_score');
        $this->assertNotNull($scoreRegression);
        $this->assertSame('critical', $scoreRegression['severity']);

        // Verify finding has Critical severity
        $finding = $this->findFindingByMetric($result['findings'], 'composite_score');
        $this->assertNotNull($finding);
        $this->assertSame(Severity::Critical, $finding->severity);
    }

    // ---------------------------------------------------------------
    // 4. Execution time regression warning
    // ---------------------------------------------------------------

    public function test_execution_time_regression_warning(): void
    {
        $queryHash = hash('sha256', strtolower(trim('select id from users')));

        $this->store->save($queryHash, [
            'composite_score' => 80.0,
            'execution_time_ms' => 10.0,
            'rows_examined' => 100,
            'timestamp' => date('c'),
        ]);

        $analyzer = new RegressionBaselineAnalyzer($this->store);

        // Current time = 18 → regression = (18 - 10) / 10 * 100 = 80% → warning (>50, <200)
        $result = $analyzer->analyze('SELECT id FROM users', [
            'composite_score' => 80.0,
            'execution_time_ms' => 18.0,
            'rows_examined' => 100,
        ]);

        $timeRegression = $this->findRegressionByMetric($result['regression']['regressions'], 'execution_time_ms');
        $this->assertNotNull($timeRegression);
        $this->assertSame('warning', $timeRegression['severity']);
    }

    // ---------------------------------------------------------------
    // 5. Execution time regression critical
    // ---------------------------------------------------------------

    public function test_execution_time_regression_critical(): void
    {
        $queryHash = hash('sha256', strtolower(trim('select name from users')));

        $this->store->save($queryHash, [
            'composite_score' => 80.0,
            'execution_time_ms' => 10.0,
            'rows_examined' => 100,
            'timestamp' => date('c'),
        ]);

        $analyzer = new RegressionBaselineAnalyzer($this->store);

        // Current time = 35 → regression = (35 - 10) / 10 * 100 = 250% → critical (>200)
        $result = $analyzer->analyze('SELECT name FROM users', [
            'composite_score' => 80.0,
            'execution_time_ms' => 35.0,
            'rows_examined' => 100,
        ]);

        $timeRegression = $this->findRegressionByMetric($result['regression']['regressions'], 'execution_time_ms');
        $this->assertNotNull($timeRegression);
        $this->assertSame('critical', $timeRegression['severity']);

        $finding = $this->findFindingByMetric($result['findings'], 'execution_time_ms');
        $this->assertNotNull($finding);
        $this->assertSame(Severity::Critical, $finding->severity);
    }

    // ---------------------------------------------------------------
    // 6. Rows examined regression
    // ---------------------------------------------------------------

    public function test_rows_examined_regression(): void
    {
        $queryHash = hash('sha256', strtolower(trim('select email from users')));

        $this->store->save($queryHash, [
            'composite_score' => 80.0,
            'execution_time_ms' => 5.0,
            'rows_examined' => 100,
            'timestamp' => date('c'),
        ]);

        $analyzer = new RegressionBaselineAnalyzer($this->store);

        // Rows 100→250 (150% increase) AND time per row degraded:
        // Baseline: 5ms / 100 = 0.05 ms/row. Current: 20ms / 250 = 0.08 ms/row.
        // Per-row degradation = (0.08 - 0.05) / 0.05 * 100 = 60% → real regression.
        $result = $analyzer->analyze('SELECT email FROM users', [
            'composite_score' => 80.0,
            'execution_time_ms' => 20.0,
            'rows_examined' => 250,
        ]);

        $rowsRegression = $this->findRegressionByMetric($result['regression']['regressions'], 'rows_examined');
        $this->assertNotNull($rowsRegression);
        $this->assertSame('warning', $rowsRegression['severity']);
        $this->assertArrayHasKey('classification', $rowsRegression);
        $this->assertSame('performance_degradation', $rowsRegression['classification']);
    }

    // ---------------------------------------------------------------
    // 7. Improvement detected (better score)
    // ---------------------------------------------------------------

    public function test_improvement_detected(): void
    {
        $queryHash = hash('sha256', strtolower(trim('select id from products')));

        $this->store->save($queryHash, [
            'composite_score' => 60.0,
            'execution_time_ms' => 20.0,
            'rows_examined' => 500,
            'timestamp' => date('c'),
        ]);

        $analyzer = new RegressionBaselineAnalyzer($this->store);

        // Better score, faster time, fewer rows
        $result = $analyzer->analyze('SELECT id FROM products', [
            'composite_score' => 85.0,
            'execution_time_ms' => 5.0,
            'rows_examined' => 100,
        ]);

        $regression = $result['regression'];
        $this->assertEmpty($regression['regressions']);
        $this->assertNotEmpty($regression['improvements']);

        $scoreImprovement = $this->findImprovementByMetric($regression['improvements'], 'composite_score');
        $this->assertNotNull($scoreImprovement);
        $this->assertLessThan(0, $scoreImprovement['change_pct']);
    }

    // ---------------------------------------------------------------
    // 8. Mixed regressions and improvements
    // ---------------------------------------------------------------

    public function test_mixed_regressions_and_improvements(): void
    {
        $queryHash = hash('sha256', strtolower(trim('select * from mixed_query')));

        $this->store->save($queryHash, [
            'composite_score' => 80.0,
            'execution_time_ms' => 10.0,
            'rows_examined' => 100,
            'timestamp' => date('c'),
        ]);

        $analyzer = new RegressionBaselineAnalyzer($this->store);

        // Score got worse (regression), but time improved, rows about the same
        $result = $analyzer->analyze('SELECT * FROM mixed_query', [
            'composite_score' => 68.0,       // 15% regression → warning
            'execution_time_ms' => 3.0,       // improvement
            'rows_examined' => 100,           // no change
        ]);

        $regression = $result['regression'];
        $this->assertNotEmpty($regression['regressions'], 'Should have score regression');
        $this->assertNotEmpty($regression['improvements'], 'Should have time improvement');

        $this->assertNotNull($this->findRegressionByMetric($regression['regressions'], 'composite_score'));
        $this->assertNotNull($this->findImprovementByMetric($regression['improvements'], 'execution_time_ms'));
    }

    // ---------------------------------------------------------------
    // 9. Trend detection: degrading (3 worsening snapshots)
    // ---------------------------------------------------------------

    public function test_trend_detection_degrading(): void
    {
        $queryHash = hash('sha256', strtolower(trim('select * from degrading')));

        // 3 snapshots with declining scores
        $this->store->save($queryHash, [
            'composite_score' => 90.0, 'execution_time_ms' => 5.0, 'rows_examined' => 50,
            'timestamp' => date('c', strtotime('-3 days')),
        ]);
        $this->store->save($queryHash, [
            'composite_score' => 70.0, 'execution_time_ms' => 5.0, 'rows_examined' => 50,
            'timestamp' => date('c', strtotime('-2 days')),
        ]);
        $this->store->save($queryHash, [
            'composite_score' => 50.0, 'execution_time_ms' => 5.0, 'rows_examined' => 50,
            'timestamp' => date('c', strtotime('-1 day')),
        ]);

        $analyzer = new RegressionBaselineAnalyzer($this->store);

        // Current is also lower — but trend is based on history only
        $result = $analyzer->analyze('SELECT * FROM degrading', [
            'composite_score' => 40.0,
            'execution_time_ms' => 5.0,
            'rows_examined' => 50,
        ]);

        $this->assertSame('degrading', $result['regression']['trend']);

        // Should have a degrading trend finding
        $trendFinding = $this->findFindingByTitle($result['findings'], 'Degrading performance trend');
        $this->assertNotNull($trendFinding);
        $this->assertSame(Severity::Warning, $trendFinding->severity);
    }

    // ---------------------------------------------------------------
    // 10. Trend detection: improving (3 improving snapshots)
    // ---------------------------------------------------------------

    public function test_trend_detection_improving(): void
    {
        $queryHash = hash('sha256', strtolower(trim('select * from improving')));

        $this->store->save($queryHash, [
            'composite_score' => 50.0, 'execution_time_ms' => 20.0, 'rows_examined' => 500,
            'timestamp' => date('c', strtotime('-3 days')),
        ]);
        $this->store->save($queryHash, [
            'composite_score' => 70.0, 'execution_time_ms' => 10.0, 'rows_examined' => 200,
            'timestamp' => date('c', strtotime('-2 days')),
        ]);
        $this->store->save($queryHash, [
            'composite_score' => 90.0, 'execution_time_ms' => 5.0, 'rows_examined' => 50,
            'timestamp' => date('c', strtotime('-1 day')),
        ]);

        $analyzer = new RegressionBaselineAnalyzer($this->store);

        $result = $analyzer->analyze('SELECT * FROM improving', [
            'composite_score' => 95.0,
            'execution_time_ms' => 3.0,
            'rows_examined' => 30,
        ]);

        $this->assertSame('improving', $result['regression']['trend']);
    }

    // ---------------------------------------------------------------
    // 11. Trend detection: stable (mixed snapshots)
    // ---------------------------------------------------------------

    public function test_trend_detection_stable(): void
    {
        $queryHash = hash('sha256', strtolower(trim('select * from stable')));

        $this->store->save($queryHash, [
            'composite_score' => 80.0, 'execution_time_ms' => 5.0, 'rows_examined' => 100,
            'timestamp' => date('c', strtotime('-3 days')),
        ]);
        $this->store->save($queryHash, [
            'composite_score' => 85.0, 'execution_time_ms' => 5.0, 'rows_examined' => 100,
            'timestamp' => date('c', strtotime('-2 days')),
        ]);
        $this->store->save($queryHash, [
            'composite_score' => 82.0, 'execution_time_ms' => 5.0, 'rows_examined' => 100,
            'timestamp' => date('c', strtotime('-1 day')),
        ]);

        $analyzer = new RegressionBaselineAnalyzer($this->store);

        $result = $analyzer->analyze('SELECT * FROM stable', [
            'composite_score' => 83.0,
            'execution_time_ms' => 5.0,
            'rows_examined' => 100,
        ]);

        $this->assertSame('stable', $result['regression']['trend']);
    }

    // ---------------------------------------------------------------
    // 12. Snapshot auto-saved after analysis
    // ---------------------------------------------------------------

    public function test_snapshot_auto_saved_after_analysis(): void
    {
        $analyzer = new RegressionBaselineAnalyzer($this->store);

        $analyzer->analyze('SELECT * FROM auto_save', [
            'composite_score' => 85.0,
            'grade' => 'B',
            'execution_time_ms' => 5.0,
            'rows_examined' => 100,
            'complexity' => 'simple',
            'primary_access_type' => 'ref',
            'indexes_used' => ['idx_name'],
            'finding_counts' => ['warning' => 1],
        ]);

        $queryHash = hash('sha256', strtolower(trim('select * from auto_save')));
        $loaded = $this->store->load($queryHash);

        $this->assertNotNull($loaded);
        $this->assertSame($queryHash, $loaded['query_hash']);
        $this->assertEquals(85.0, $loaded['composite_score']);
        $this->assertSame('B', $loaded['grade']);
        $this->assertEquals(5.0, $loaded['execution_time_ms']);
        $this->assertSame(100, $loaded['rows_examined']);
        $this->assertSame('simple', $loaded['complexity']);
        $this->assertSame('ref', $loaded['access_type']);
        $this->assertSame(['idx_name'], $loaded['indexes_used']);
        $this->assertSame(['warning' => 1], $loaded['finding_counts']);
        $this->assertArrayHasKey('timestamp', $loaded);
    }

    // ---------------------------------------------------------------
    // 13. Custom thresholds work
    // ---------------------------------------------------------------

    public function test_custom_thresholds_work(): void
    {
        $queryHash = hash('sha256', strtolower(trim('select * from custom_thresholds')));

        $this->store->save($queryHash, [
            'composite_score' => 80.0,
            'execution_time_ms' => 10.0,
            'rows_examined' => 100,
            'timestamp' => date('c'),
        ]);

        // Very strict thresholds: score warning at 5%, critical at 10%
        $analyzer = new RegressionBaselineAnalyzer(
            store: $this->store,
            scoreWarningThreshold: 5.0,
            scoreCriticalThreshold: 10.0,
        );

        // 8% score drop → normally warning (default 10%), but with custom threshold → critical (>10% = no, >5% = yes)
        // (80 - 73.6) / 80 * 100 = 8% → warning with custom (>5, <10)
        $result = $analyzer->analyze('SELECT * FROM custom_thresholds', [
            'composite_score' => 73.6,
            'execution_time_ms' => 10.0,
            'rows_examined' => 100,
        ]);

        $scoreRegression = $this->findRegressionByMetric($result['regression']['regressions'], 'composite_score');
        $this->assertNotNull($scoreRegression);
        $this->assertSame('warning', $scoreRegression['severity']);

        // Now 12% drop → critical with custom threshold (>10)
        // Reset store to avoid second snapshot affecting averages
        $this->removeDirectory($this->tempDir);
        $store2 = new BaselineStore($this->tempDir);
        $store2->save($queryHash, [
            'composite_score' => 80.0,
            'execution_time_ms' => 10.0,
            'rows_examined' => 100,
            'timestamp' => date('c'),
        ]);

        $analyzer2 = new RegressionBaselineAnalyzer(
            store: $store2,
            scoreWarningThreshold: 5.0,
            scoreCriticalThreshold: 10.0,
        );

        $result2 = $analyzer2->analyze('SELECT * FROM custom_thresholds', [
            'composite_score' => 69.0,
            'execution_time_ms' => 10.0,
            'rows_examined' => 100,
        ]);

        $scoreRegression2 = $this->findRegressionByMetric($result2['regression']['regressions'], 'composite_score');
        $this->assertNotNull($scoreRegression2);
        $this->assertSame('critical', $scoreRegression2['severity']);
    }

    // ---------------------------------------------------------------
    // 14. Normalized SQL hashing (same query different whitespace = same hash)
    // ---------------------------------------------------------------

    public function test_normalized_sql_hashing(): void
    {
        $analyzer = new RegressionBaselineAnalyzer($this->store);

        // First call with formatted SQL
        $analyzer->analyze('SELECT  *  FROM   users   WHERE  id = 1', [
            'composite_score' => 80.0,
            'execution_time_ms' => 5.0,
            'rows_examined' => 100,
        ]);

        // Second call with different whitespace — same query semantically
        $result = $analyzer->analyze('select * from users where id = 1', [
            'composite_score' => 80.0,
            'execution_time_ms' => 5.0,
            'rows_examined' => 100,
        ]);

        // Should have baseline from first call
        $this->assertTrue($result['regression']['has_baseline']);
        $this->assertSame(1, $result['regression']['baseline_count']);
    }

    // ---------------------------------------------------------------
    // 15. Empty metrics handled gracefully
    // ---------------------------------------------------------------

    public function test_empty_metrics_handled_gracefully(): void
    {
        $analyzer = new RegressionBaselineAnalyzer($this->store);

        // First call to create baseline
        $analyzer->analyze('SELECT 1', []);

        // Second call with empty metrics — should not throw
        $result = $analyzer->analyze('SELECT 1', []);

        $this->assertTrue($result['regression']['has_baseline']);
        $this->assertSame('stable', $result['regression']['trend']);
    }

    // ---------------------------------------------------------------
    // 16. Baseline count reflects actual stored snapshots
    // ---------------------------------------------------------------

    public function test_baseline_count_reflects_stored_snapshots(): void
    {
        $analyzer = new RegressionBaselineAnalyzer($this->store);

        $analyzer->analyze('SELECT id FROM counts', [
            'composite_score' => 80.0, 'execution_time_ms' => 5.0, 'rows_examined' => 100,
        ]);
        $analyzer->analyze('SELECT id FROM counts', [
            'composite_score' => 82.0, 'execution_time_ms' => 4.0, 'rows_examined' => 90,
        ]);
        $analyzer->analyze('SELECT id FROM counts', [
            'composite_score' => 84.0, 'execution_time_ms' => 3.0, 'rows_examined' => 80,
        ]);

        $result = $analyzer->analyze('SELECT id FROM counts', [
            'composite_score' => 86.0, 'execution_time_ms' => 2.0, 'rows_examined' => 70,
        ]);

        $this->assertTrue($result['regression']['has_baseline']);
        $this->assertSame(3, $result['regression']['baseline_count']);
    }

    // ---------------------------------------------------------------
    // 17. Regression findings have correct category
    // ---------------------------------------------------------------

    public function test_regression_findings_have_correct_category(): void
    {
        $queryHash = hash('sha256', strtolower(trim('select * from category_check')));

        $this->store->save($queryHash, [
            'composite_score' => 80.0,
            'execution_time_ms' => 10.0,
            'rows_examined' => 100,
            'timestamp' => date('c'),
        ]);

        $analyzer = new RegressionBaselineAnalyzer($this->store);

        $result = $analyzer->analyze('SELECT * FROM category_check', [
            'composite_score' => 50.0,
            'execution_time_ms' => 40.0,
            'rows_examined' => 500,
        ]);

        foreach ($result['findings'] as $finding) {
            $this->assertSame('regression', $finding->category);
        }
    }

    // ---------------------------------------------------------------
    // 18. Trend is stable with fewer than 3 history entries
    // ---------------------------------------------------------------

    public function test_trend_stable_with_fewer_than_3_entries(): void
    {
        $queryHash = hash('sha256', strtolower(trim('select * from few_entries')));

        $this->store->save($queryHash, [
            'composite_score' => 90.0, 'execution_time_ms' => 5.0, 'rows_examined' => 50,
            'timestamp' => date('c'),
        ]);
        $this->store->save($queryHash, [
            'composite_score' => 50.0, 'execution_time_ms' => 5.0, 'rows_examined' => 50,
            'timestamp' => date('c'),
        ]);

        $analyzer = new RegressionBaselineAnalyzer($this->store);

        $result = $analyzer->analyze('SELECT * FROM few_entries', [
            'composite_score' => 30.0,
            'execution_time_ms' => 5.0,
            'rows_examined' => 50,
        ]);

        // Only 2 history entries, need 3 for trend detection
        $this->assertSame('stable', $result['regression']['trend']);
    }

    // ---------------------------------------------------------------
    // Helper methods
    // ---------------------------------------------------------------

    /**
     * @param  array<int, array<string, mixed>>  $regressions
     * @return array<string, mixed>|null
     */
    private function findRegressionByMetric(array $regressions, string $metric): ?array
    {
        foreach ($regressions as $reg) {
            if ($reg['metric'] === $metric) {
                return $reg;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $improvements
     * @return array<string, mixed>|null
     */
    private function findImprovementByMetric(array $improvements, string $metric): ?array
    {
        foreach ($improvements as $imp) {
            if ($imp['metric'] === $metric) {
                return $imp;
            }
        }

        return null;
    }

    /**
     * @param  Finding[]  $findings
     */
    private function findFindingByMetric(array $findings, string $metric): ?Finding
    {
        foreach ($findings as $finding) {
            if (str_contains($finding->title, $metric)) {
                return $finding;
            }
        }

        return null;
    }

    /**
     * @param  Finding[]  $findings
     */
    private function findFindingByTitle(array $findings, string $title): ?Finding
    {
        foreach ($findings as $finding) {
            if ($finding->title === $title) {
                return $finding;
            }
        }

        return null;
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $path.'/'.$item;
            if (is_dir($fullPath)) {
                $this->removeDirectory($fullPath);
            } else {
                unlink($fullPath);
            }
        }

        rmdir($path);
    }
}
