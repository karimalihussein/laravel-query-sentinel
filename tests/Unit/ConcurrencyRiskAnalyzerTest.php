<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QuerySentinel\Analyzers\ConcurrencyRiskAnalyzer;
use QuerySentinel\Enums\ComplexityClass;
use QuerySentinel\Enums\Severity;
use QuerySentinel\Support\ExecutionProfile;

final class ConcurrencyRiskAnalyzerTest extends TestCase
{
    private ConcurrencyRiskAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new ConcurrencyRiskAnalyzer;
    }

    // ---------------------------------------------------------------
    // Helper to build an ExecutionProfile with defaults
    // ---------------------------------------------------------------

    private function makeProfile(
        int $nestedLoopDepth = 1,
        int $logicalReads = 0,
        int $physicalReads = 0,
    ): ExecutionProfile {
        return new ExecutionProfile(
            nestedLoopDepth: $nestedLoopDepth,
            joinFanouts: [],
            btreeDepths: [],
            logicalReads: $logicalReads,
            physicalReads: $physicalReads,
            scanComplexity: ComplexityClass::Linear,
            sortComplexity: ComplexityClass::Constant,
        );
    }

    // ---------------------------------------------------------------
    // 1. Table scan → 'table' lock scope
    // ---------------------------------------------------------------

    public function test_table_scan_produces_table_lock_scope(): void
    {
        $sql = 'UPDATE users SET active = 0 WHERE name = "John"';
        $metrics = [
            'has_table_scan' => true,
            'primary_access_type' => 'table_scan',
            'is_index_backed' => false,
            'rows_examined' => 10000,
            'execution_time_ms' => 50.0,
        ];

        $result = $this->analyzer->analyze($sql, $metrics, null);

        $this->assertSame('table', $result['concurrency']['lock_scope']);
    }

    // ---------------------------------------------------------------
    // 2. Index range scan → 'range' lock scope
    // ---------------------------------------------------------------

    public function test_index_range_scan_produces_range_lock_scope(): void
    {
        $sql = 'UPDATE orders SET status = "done" WHERE created_at BETWEEN "2024-01-01" AND "2024-12-31"';
        $metrics = [
            'has_table_scan' => false,
            'primary_access_type' => 'index_range_scan',
            'is_index_backed' => true,
            'rows_examined' => 500,
            'execution_time_ms' => 10.0,
        ];

        $result = $this->analyzer->analyze($sql, $metrics, null);

        $this->assertSame('range', $result['concurrency']['lock_scope']);
    }

    // ---------------------------------------------------------------
    // 3. Point lookup → 'row' lock scope
    // ---------------------------------------------------------------

    public function test_point_lookup_produces_row_lock_scope(): void
    {
        $sql = 'UPDATE users SET active = 0 WHERE id = 1';
        $metrics = [
            'has_table_scan' => false,
            'primary_access_type' => 'const_row',
            'is_index_backed' => true,
            'rows_examined' => 1,
            'execution_time_ms' => 0.5,
        ];

        $result = $this->analyzer->analyze($sql, $metrics, null);

        $this->assertSame('row', $result['concurrency']['lock_scope']);
    }

    // ---------------------------------------------------------------
    // 4. Default unknown lock scope
    // ---------------------------------------------------------------

    public function test_unknown_lock_scope_when_no_access_type(): void
    {
        $sql = 'UPDATE test SET x = 1';
        $metrics = [
            'has_table_scan' => false,
            'primary_access_type' => null,
            'is_index_backed' => false,
            'rows_examined' => 0,
            'execution_time_ms' => 0.0,
        ];

        $result = $this->analyzer->analyze($sql, $metrics, null);

        $this->assertSame('unknown', $result['concurrency']['lock_scope']);
    }

    // ---------------------------------------------------------------
    // 5. Multi-table query increases deadlock risk
    // ---------------------------------------------------------------

    public function test_multi_table_join_increases_deadlock_risk(): void
    {
        $sqlJoin = 'DELETE u FROM users u JOIN orders o ON u.id = o.user_id';
        $sqlSingle = 'UPDATE users SET active = 0 WHERE id = 1';
        $metrics = [
            'has_table_scan' => false,
            'primary_access_type' => 'const_row',
            'is_index_backed' => true,
            'rows_examined' => 1,
            'execution_time_ms' => 0.5,
        ];

        $resultJoin = $this->analyzer->analyze($sqlJoin, $metrics, null);
        $resultSingle = $this->analyzer->analyze($sqlSingle, $metrics, null);

        $this->assertGreaterThan(
            $resultSingle['concurrency']['deadlock_risk'],
            $resultJoin['concurrency']['deadlock_risk'],
        );
    }

    // ---------------------------------------------------------------
    // 6. No index increases deadlock risk
    // ---------------------------------------------------------------

    public function test_no_index_increases_deadlock_risk(): void
    {
        $sql = 'UPDATE users SET active = 0 WHERE name = "John"';
        $metricsNoIndex = [
            'has_table_scan' => false,
            'primary_access_type' => 'table_scan',
            'is_index_backed' => false,
            'rows_examined' => 1000,
            'execution_time_ms' => 10.0,
        ];
        $metricsWithIndex = [
            'has_table_scan' => false,
            'primary_access_type' => 'index_lookup',
            'is_index_backed' => true,
            'rows_examined' => 1000,
            'execution_time_ms' => 10.0,
        ];

        $resultNoIndex = $this->analyzer->analyze($sql, $metricsNoIndex, null);
        $resultWithIndex = $this->analyzer->analyze($sql, $metricsWithIndex, null);

        $this->assertGreaterThan(
            $resultWithIndex['concurrency']['deadlock_risk'],
            $resultNoIndex['concurrency']['deadlock_risk'],
        );
    }

    // ---------------------------------------------------------------
    // 7. Deep nested loops increase deadlock risk
    // ---------------------------------------------------------------

    public function test_deep_nested_loops_increase_deadlock_risk(): void
    {
        $sql = 'UPDATE users SET active = 0 WHERE id = 1';
        $metrics = [
            'has_table_scan' => false,
            'primary_access_type' => 'const_row',
            'is_index_backed' => true,
            'rows_examined' => 1,
            'execution_time_ms' => 0.5,
        ];

        $profileDeep = $this->makeProfile(nestedLoopDepth: 3);
        $profileShallow = $this->makeProfile(nestedLoopDepth: 1);

        $resultDeep = $this->analyzer->analyze($sql, $metrics, $profileDeep);
        $resultShallow = $this->analyzer->analyze($sql, $metrics, $profileShallow);

        $this->assertGreaterThan(
            $resultShallow['concurrency']['deadlock_risk'],
            $resultDeep['concurrency']['deadlock_risk'],
        );
    }

    // ---------------------------------------------------------------
    // 8. All deadlock risk factors combined = high risk
    // ---------------------------------------------------------------

    public function test_all_deadlock_factors_combined_high_risk(): void
    {
        $sql = 'UPDATE u SET active = 0 FROM users u JOIN orders o ON u.id = o.user_id WHERE o.id IN (SELECT id FROM returns)';
        $metrics = [
            'has_table_scan' => false,
            'primary_access_type' => 'table_scan',
            'is_index_backed' => false,
            'rows_examined' => 100000,
            'execution_time_ms' => 500.0,
        ];

        $profile = $this->makeProfile(nestedLoopDepth: 3);

        $result = $this->analyzer->analyze($sql, $metrics, $profile);

        // JOIN (+0.3) + subquery (+0.2) + no index (+0.3) + nested depth>2 (+0.2) = 1.0
        $this->assertSame('high', $result['concurrency']['deadlock_risk_label']);
        $this->assertGreaterThan(0.6, $result['concurrency']['deadlock_risk']);
    }

    // ---------------------------------------------------------------
    // 9. Low deadlock risk for simple indexed query
    // ---------------------------------------------------------------

    public function test_low_deadlock_risk_for_simple_indexed_query(): void
    {
        $sql = 'UPDATE users SET active = 0 WHERE id = 42';
        $metrics = [
            'has_table_scan' => false,
            'primary_access_type' => 'const_row',
            'is_index_backed' => true,
            'rows_examined' => 1,
            'execution_time_ms' => 0.2,
        ];

        $result = $this->analyzer->analyze($sql, $metrics, null);

        $this->assertSame('low', $result['concurrency']['deadlock_risk_label']);
        $this->assertLessThan(0.3, $result['concurrency']['deadlock_risk']);
    }

    // ---------------------------------------------------------------
    // 10. Contention score calculation with execution time and rows
    // ---------------------------------------------------------------

    public function test_contention_score_calculation(): void
    {
        $sql = 'UPDATE orders SET status = "done" WHERE status = "pending"';
        $metrics = [
            'has_table_scan' => false,
            'primary_access_type' => 'index_range_scan',
            'is_index_backed' => true,
            'rows_examined' => 50000,
            'execution_time_ms' => 100.0,
            'nested_loop_depth' => 1,
        ];

        $result = $this->analyzer->analyze($sql, $metrics, null);

        // lock_duration_factor = 100.0 * (1 + 1 * 0.5) = 150.0
        // contention_score = round(150.0 * 50000 / 10000, 4) = 750.0
        $this->assertSame(750.0, $result['concurrency']['contention_score']);
    }

    // ---------------------------------------------------------------
    // 11. Zero execution time → zero contention
    // ---------------------------------------------------------------

    public function test_zero_execution_time_zero_contention(): void
    {
        $sql = 'UPDATE users SET active = 0 WHERE id = 1';
        $metrics = [
            'has_table_scan' => false,
            'primary_access_type' => 'const_row',
            'is_index_backed' => true,
            'rows_examined' => 1,
            'execution_time_ms' => 0.0,
        ];

        $result = $this->analyzer->analyze($sql, $metrics, null);

        $this->assertSame(0.0, $result['concurrency']['contention_score']);
    }

    // ---------------------------------------------------------------
    // 12. Isolation impact for table lock scope (write query)
    // ---------------------------------------------------------------

    public function test_isolation_impact_for_table_lock_scope(): void
    {
        $sql = 'UPDATE users SET active = 0 WHERE name LIKE "%test%"';
        $metrics = [
            'has_table_scan' => true,
            'primary_access_type' => 'table_scan',
            'is_index_backed' => false,
            'rows_examined' => 10000,
            'execution_time_ms' => 50.0,
        ];

        $result = $this->analyzer->analyze($sql, $metrics, null);

        $this->assertStringContainsString('REPEATABLE READ', $result['concurrency']['isolation_impact']);
        $this->assertStringContainsString('gap locks', $result['concurrency']['isolation_impact']);
        $this->assertStringContainsString('READ COMMITTED', $result['concurrency']['isolation_impact']);
    }

    // ---------------------------------------------------------------
    // 13. Isolation impact for read-only queries
    // ---------------------------------------------------------------

    public function test_isolation_impact_for_read_only_queries(): void
    {
        $sql = 'SELECT * FROM users WHERE id = 1';
        $metrics = [
            'has_table_scan' => false,
            'primary_access_type' => 'const_row',
            'is_index_backed' => true,
            'rows_examined' => 1,
            'execution_time_ms' => 0.2,
        ];

        $result = $this->analyzer->analyze($sql, $metrics, null);

        $this->assertStringContainsString('MVCC snapshots', $result['concurrency']['isolation_impact']);
        $this->assertStringContainsString('No locks acquired', $result['concurrency']['isolation_impact']);
    }

    // ---------------------------------------------------------------
    // 14. Write query (UPDATE) with table scan → critical finding
    // ---------------------------------------------------------------

    public function test_update_with_table_scan_critical_finding(): void
    {
        $sql = 'UPDATE users SET active = 0 WHERE name LIKE "%inactive%"';
        $metrics = [
            'has_table_scan' => true,
            'primary_access_type' => 'table_scan',
            'is_index_backed' => false,
            'rows_examined' => 50000,
            'execution_time_ms' => 200.0,
        ];

        $result = $this->analyzer->analyze($sql, $metrics, null);

        $criticalFindings = array_filter(
            $result['findings'],
            fn ($f) => $f->severity === Severity::Critical,
        );
        $this->assertNotEmpty($criticalFindings);

        $finding = array_values($criticalFindings)[0];
        $this->assertSame('concurrency', $finding->category);
        $this->assertStringContainsString('Write query', $finding->title);
        $this->assertStringContainsString('table scan', $finding->title);
    }

    // ---------------------------------------------------------------
    // 15. Write query (DELETE) detection
    // ---------------------------------------------------------------

    public function test_delete_query_detection(): void
    {
        $sql = 'DELETE FROM sessions WHERE expires_at < NOW()';
        $metrics = [
            'has_table_scan' => true,
            'primary_access_type' => 'table_scan',
            'is_index_backed' => false,
            'rows_examined' => 10000,
            'execution_time_ms' => 100.0,
        ];

        $result = $this->analyzer->analyze($sql, $metrics, null);

        $criticalFindings = array_filter(
            $result['findings'],
            fn ($f) => $f->severity === Severity::Critical,
        );
        $this->assertNotEmpty($criticalFindings, 'DELETE with table scan should produce a critical finding');

        $finding = array_values($criticalFindings)[0];
        $this->assertStringContainsString('Write query', $finding->title);
    }

    // ---------------------------------------------------------------
    // 16. Recommendations generated for high-risk scenarios
    // ---------------------------------------------------------------

    public function test_recommendations_for_high_risk_scenarios(): void
    {
        $sql = 'UPDATE u SET active = 0 FROM users u JOIN orders o ON u.id = o.user_id WHERE o.id IN (SELECT id FROM returns)';
        $metrics = [
            'has_table_scan' => true,
            'primary_access_type' => 'table_scan',
            'is_index_backed' => false,
            'rows_examined' => 100000,
            'execution_time_ms' => 500.0,
            'nested_loop_depth' => 1,
        ];

        $profile = $this->makeProfile(nestedLoopDepth: 3);

        $result = $this->analyzer->analyze($sql, $metrics, $profile);

        $recommendations = $result['concurrency']['recommendations'];
        $this->assertNotEmpty($recommendations);

        // Should have index recommendation (table lock scope)
        $hasIndexRec = false;
        foreach ($recommendations as $rec) {
            if (str_contains($rec, 'Add an index')) {
                $hasIndexRec = true;
                break;
            }
        }
        $this->assertTrue($hasIndexRec, 'Expected recommendation to add an index');

        // Should have deadlock recommendation (high risk)
        $hasDeadlockRec = false;
        foreach ($recommendations as $rec) {
            if (str_contains($rec, 'smaller transactions') || str_contains($rec, 'consistent table access')) {
                $hasDeadlockRec = true;
                break;
            }
        }
        $this->assertTrue($hasDeadlockRec, 'Expected deadlock-related recommendation');
    }

    // ---------------------------------------------------------------
    // 17. No findings for simple, well-indexed point lookup
    // ---------------------------------------------------------------

    public function test_no_findings_for_well_indexed_point_lookup(): void
    {
        $sql = 'UPDATE users SET active = 0 WHERE id = 42';
        $metrics = [
            'has_table_scan' => false,
            'primary_access_type' => 'const_row',
            'is_index_backed' => true,
            'rows_examined' => 1,
            'execution_time_ms' => 0.1,
        ];

        $result = $this->analyzer->analyze($sql, $metrics, null);

        $this->assertEmpty($result['findings']);
        $this->assertSame('row', $result['concurrency']['lock_scope']);
        $this->assertSame('low', $result['concurrency']['deadlock_risk_label']);
        $this->assertEmpty($result['concurrency']['recommendations']);
    }

    // ---------------------------------------------------------------
    // 18. Custom profile with nested loop depth affects scoring
    // ---------------------------------------------------------------

    public function test_profile_nested_loop_depth_affects_contention(): void
    {
        $sql = 'UPDATE orders SET status = "done" WHERE status = "pending"';
        $metrics = [
            'has_table_scan' => false,
            'primary_access_type' => 'index_range_scan',
            'is_index_backed' => true,
            'rows_examined' => 10000,
            'execution_time_ms' => 50.0,
            'nested_loop_depth' => 1,
        ];

        $profileDeep = $this->makeProfile(nestedLoopDepth: 4);
        $profileShallow = $this->makeProfile(nestedLoopDepth: 1);

        $resultDeep = $this->analyzer->analyze($sql, $metrics, $profileDeep);
        $resultShallow = $this->analyzer->analyze($sql, $metrics, $profileShallow);

        // Profile nested depth is used for contention: deep should have higher contention
        // Deep: 50 * (1 + 4*0.5) * 10000 / 10000 = 50 * 3 = 150
        // Shallow: 50 * (1 + 1*0.5) * 10000 / 10000 = 50 * 1.5 = 75
        $this->assertGreaterThan(
            $resultShallow['concurrency']['contention_score'],
            $resultDeep['concurrency']['contention_score'],
        );
        $this->assertSame(150.0, $resultDeep['concurrency']['contention_score']);
        $this->assertSame(75.0, $resultShallow['concurrency']['contention_score']);
    }

    // ---------------------------------------------------------------
    // 19. Single row lookup (eq_ref) → row lock scope
    // ---------------------------------------------------------------

    public function test_single_row_lookup_produces_row_lock_scope(): void
    {
        $sql = 'UPDATE users SET active = 0 WHERE id = 1';
        $metrics = [
            'has_table_scan' => false,
            'primary_access_type' => 'single_row_lookup',
            'is_index_backed' => true,
            'rows_examined' => 1,
            'execution_time_ms' => 0.3,
        ];

        $result = $this->analyzer->analyze($sql, $metrics, null);

        $this->assertSame('row', $result['concurrency']['lock_scope']);
    }

    // ---------------------------------------------------------------
    // 20. Gap lock scope for index_lookup
    // ---------------------------------------------------------------

    public function test_index_lookup_produces_gap_lock_scope(): void
    {
        $sql = 'UPDATE orders SET processed = 1 WHERE user_id = 42';
        $metrics = [
            'has_table_scan' => false,
            'primary_access_type' => 'index_lookup',
            'is_index_backed' => true,
            'rows_examined' => 10,
            'execution_time_ms' => 1.0,
        ];

        $result = $this->analyzer->analyze($sql, $metrics, null);

        $this->assertSame('gap', $result['concurrency']['lock_scope']);
    }

    // ---------------------------------------------------------------
    // 21. High contention score produces warning finding
    // ---------------------------------------------------------------

    public function test_high_contention_produces_warning_finding(): void
    {
        $sql = 'UPDATE large_table SET status = "inactive" WHERE category = "active"';
        $metrics = [
            'has_table_scan' => false,
            'primary_access_type' => 'index_range_scan',
            'is_index_backed' => true,
            'rows_examined' => 500000,
            'execution_time_ms' => 1000.0,
            'nested_loop_depth' => 1,
        ];

        $result = $this->analyzer->analyze($sql, $metrics, null);

        // contention = 1000 * (1 + 1*0.5) * 500000 / 10000 = 1500 * 50 = 75000
        $this->assertGreaterThan(100, $result['concurrency']['contention_score']);

        $contentionFindings = array_filter(
            $result['findings'],
            fn ($f) => str_contains($f->title, 'contention'),
        );
        $this->assertNotEmpty($contentionFindings);
        $this->assertSame(Severity::Warning, array_values($contentionFindings)[0]->severity);
    }

    // ---------------------------------------------------------------
    // 22. Subquery detected increases deadlock risk
    // ---------------------------------------------------------------

    public function test_subquery_increases_deadlock_risk(): void
    {
        $sqlWithSubquery = 'DELETE FROM users WHERE id IN (SELECT user_id FROM orders)';
        $sqlWithout = 'DELETE FROM users WHERE id = 1';
        $metrics = [
            'has_table_scan' => false,
            'primary_access_type' => 'const_row',
            'is_index_backed' => true,
            'rows_examined' => 1,
            'execution_time_ms' => 0.5,
        ];

        $resultWith = $this->analyzer->analyze($sqlWithSubquery, $metrics, null);
        $resultWithout = $this->analyzer->analyze($sqlWithout, $metrics, null);

        $this->assertGreaterThan(
            $resultWithout['concurrency']['deadlock_risk'],
            $resultWith['concurrency']['deadlock_risk'],
        );
    }

    // ---------------------------------------------------------------
    // 23. Range lock scope with write query produces narrow recommendation
    // ---------------------------------------------------------------

    public function test_range_lock_with_write_produces_narrow_recommendation(): void
    {
        $sql = 'UPDATE orders SET status = "processed" WHERE created_at BETWEEN "2024-01-01" AND "2024-06-30"';
        $metrics = [
            'has_table_scan' => false,
            'primary_access_type' => 'index_range_scan',
            'is_index_backed' => true,
            'rows_examined' => 500,
            'execution_time_ms' => 10.0,
        ];

        $result = $this->analyzer->analyze($sql, $metrics, null);

        $hasNarrowRec = false;
        foreach ($result['concurrency']['recommendations'] as $rec) {
            if (str_contains($rec, 'Narrow the range condition')) {
                $hasNarrowRec = true;
                break;
            }
        }
        $this->assertTrue($hasNarrowRec, 'Expected recommendation to narrow range condition for write query');
    }

    // ---------------------------------------------------------------
    // 24. Isolation impact for range scope write query
    // ---------------------------------------------------------------

    public function test_isolation_impact_for_range_write(): void
    {
        $sql = 'DELETE FROM orders WHERE created_at < "2023-01-01"';
        $metrics = [
            'has_table_scan' => false,
            'primary_access_type' => 'index_range_scan',
            'is_index_backed' => true,
            'rows_examined' => 1000,
            'execution_time_ms' => 20.0,
        ];

        $result = $this->analyzer->analyze($sql, $metrics, null);

        $this->assertStringContainsString('next-key locks', $result['concurrency']['isolation_impact']);
        $this->assertStringContainsString('REPEATABLE READ', $result['concurrency']['isolation_impact']);
    }

    // ---------------------------------------------------------------
    // 25. Return structure has all required keys
    // ---------------------------------------------------------------

    public function test_return_structure_has_all_required_keys(): void
    {
        $sql = 'SELECT * FROM users WHERE id = 1';
        $metrics = [
            'has_table_scan' => false,
            'primary_access_type' => 'const_row',
            'is_index_backed' => true,
            'rows_examined' => 1,
            'execution_time_ms' => 0.1,
        ];

        $result = $this->analyzer->analyze($sql, $metrics, null);

        $this->assertArrayHasKey('concurrency', $result);
        $this->assertArrayHasKey('findings', $result);

        $concurrency = $result['concurrency'];
        $this->assertArrayHasKey('lock_scope', $concurrency);
        $this->assertArrayHasKey('deadlock_risk', $concurrency);
        $this->assertArrayHasKey('deadlock_risk_label', $concurrency);
        $this->assertArrayHasKey('contention_score', $concurrency);
        $this->assertArrayHasKey('isolation_impact', $concurrency);
        $this->assertArrayHasKey('recommendations', $concurrency);

        $this->assertIsString($concurrency['lock_scope']);
        $this->assertIsFloat($concurrency['deadlock_risk']);
        $this->assertIsString($concurrency['deadlock_risk_label']);
        $this->assertIsFloat($concurrency['contention_score']);
        $this->assertIsString($concurrency['isolation_impact']);
        $this->assertIsArray($concurrency['recommendations']);
        $this->assertIsArray($result['findings']);
    }

    // ---------------------------------------------------------------
    // 26. INSERT is detected as write query
    // ---------------------------------------------------------------

    public function test_insert_detected_as_write_query(): void
    {
        $sql = 'INSERT INTO audit_log (user_id, action) SELECT id, "login" FROM users WHERE active = 1';
        $metrics = [
            'has_table_scan' => true,
            'primary_access_type' => 'table_scan',
            'is_index_backed' => false,
            'rows_examined' => 5000,
            'execution_time_ms' => 30.0,
        ];

        $result = $this->analyzer->analyze($sql, $metrics, null);

        $criticalFindings = array_filter(
            $result['findings'],
            fn ($f) => $f->severity === Severity::Critical,
        );
        $this->assertNotEmpty($criticalFindings, 'INSERT with table scan should produce a critical finding');
    }

    // ---------------------------------------------------------------
    // 27. Moderate deadlock risk produces optimization finding
    // ---------------------------------------------------------------

    public function test_moderate_deadlock_risk_produces_optimization_finding(): void
    {
        // JOIN (+0.3) = 0.3 → moderate (>= 0.3 and <= 0.6)
        $sql = 'DELETE u FROM users u JOIN orders o ON u.id = o.user_id WHERE u.id = 1';
        $metrics = [
            'has_table_scan' => false,
            'primary_access_type' => 'const_row',
            'is_index_backed' => true,
            'rows_examined' => 10,
            'execution_time_ms' => 1.0,
        ];

        $result = $this->analyzer->analyze($sql, $metrics, null);

        $this->assertSame('moderate', $result['concurrency']['deadlock_risk_label']);

        $optimizationFindings = array_filter(
            $result['findings'],
            fn ($f) => $f->severity === Severity::Optimization && str_contains($f->title, 'deadlock'),
        );
        $this->assertNotEmpty($optimizationFindings, 'Moderate deadlock risk should produce an Optimization finding');
    }

    // ---------------------------------------------------------------
    // 28. zero_row_const produces row lock scope
    // ---------------------------------------------------------------

    public function test_zero_row_const_produces_row_lock_scope(): void
    {
        $sql = 'UPDATE empty_table SET x = 1 WHERE id = 1';
        $metrics = [
            'has_table_scan' => false,
            'primary_access_type' => 'zero_row_const',
            'is_index_backed' => true,
            'rows_examined' => 0,
            'execution_time_ms' => 0.0,
        ];

        $result = $this->analyzer->analyze($sql, $metrics, null);

        $this->assertSame('row', $result['concurrency']['lock_scope']);
    }

    // ---------------------------------------------------------------
    // Plain SELECT — MVCC consistent read, no locks
    // ---------------------------------------------------------------

    public function test_plain_select_no_locks(): void
    {
        $sql = 'SELECT * FROM users WHERE LOWER(email) = "test@example.com"';
        $metrics = [
            'has_table_scan' => true,
            'primary_access_type' => 'table_scan',
            'is_index_backed' => false,
            'rows_examined' => 10000,
            'execution_time_ms' => 50.0,
        ];

        $result = $this->analyzer->analyze($sql, $metrics, null);

        $this->assertSame('none', $result['concurrency']['lock_scope']);
        $this->assertSame(0.0, $result['concurrency']['deadlock_risk']);
        $this->assertSame('none', $result['concurrency']['deadlock_risk_label']);
        $this->assertSame(0.0, $result['concurrency']['contention_score']);
        $this->assertEmpty($result['concurrency']['recommendations']);
        $this->assertEmpty($result['findings']);
    }

    // ---------------------------------------------------------------
    // SELECT FOR UPDATE — full lock analysis
    // ---------------------------------------------------------------

    public function test_select_for_update_full_analysis(): void
    {
        $sql = 'SELECT * FROM users WHERE id = 1 FOR UPDATE';
        $metrics = [
            'has_table_scan' => false,
            'primary_access_type' => 'const_row',
            'is_index_backed' => true,
            'rows_examined' => 1,
            'execution_time_ms' => 0.5,
        ];

        $result = $this->analyzer->analyze($sql, $metrics, null);

        // Locking read gets full analysis, not the MVCC short-circuit
        $this->assertSame('row', $result['concurrency']['lock_scope']);
        $this->assertSame('low', $result['concurrency']['deadlock_risk_label']);
    }

    // ---------------------------------------------------------------
    // SELECT FOR SHARE — full lock analysis
    // ---------------------------------------------------------------

    public function test_select_for_share_full_analysis(): void
    {
        $sql = 'SELECT * FROM accounts WHERE id = 10 FOR SHARE';
        $metrics = [
            'has_table_scan' => false,
            'primary_access_type' => 'const_row',
            'is_index_backed' => true,
            'rows_examined' => 1,
            'execution_time_ms' => 0.3,
        ];

        $result = $this->analyzer->analyze($sql, $metrics, null);

        $this->assertSame('row', $result['concurrency']['lock_scope']);
        $this->assertIsFloat($result['concurrency']['deadlock_risk']);
    }

    // ---------------------------------------------------------------
    // Plain SELECT with table scan — still no locks (MVCC)
    // ---------------------------------------------------------------

    public function test_plain_select_with_table_scan_no_locks(): void
    {
        $sql = 'SELECT * FROM large_table WHERE unindexed_col = "value"';
        $metrics = [
            'has_table_scan' => true,
            'primary_access_type' => 'table_scan',
            'is_index_backed' => false,
            'rows_examined' => 100000,
            'execution_time_ms' => 500.0,
        ];

        $result = $this->analyzer->analyze($sql, $metrics, null);

        // Even with table scan, plain SELECT acquires no locks under MVCC
        $this->assertSame('none', $result['concurrency']['lock_scope']);
        $this->assertSame(0.0, $result['concurrency']['deadlock_risk']);
        $this->assertEmpty($result['findings']);
    }
}
