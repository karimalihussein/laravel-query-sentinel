<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QuerySentinel\Analyzers\HypotheticalIndexAnalyzer;
use QuerySentinel\Contracts\DriverInterface;
use QuerySentinel\Enums\Severity;
use QuerySentinel\Support\Finding;

final class HypotheticalIndexAnalyzerTest extends TestCase
{
    private HypotheticalIndexAnalyzer $analyzer;

    /** @var string[] */
    private array $executedDdl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->executedDdl = [];
        $this->analyzer = new HypotheticalIndexAnalyzer(
            maxSimulations: 3,
            timeoutSeconds: 5,
            allowedEnvironments: ['local', 'testing'],
            ddlExecutor: function (string $ddl): void {
                $this->executedDdl[] = $ddl;
            },
        );
    }

    // ---------------------------------------------------------------
    // Helper: create a mock DriverInterface
    // ---------------------------------------------------------------

    /**
     * @param  array<int, array<int, array<string, mixed>>>  $explainResults
     */
    private function mockDriver(array $explainResults): DriverInterface
    {
        $driver = $this->createMock(DriverInterface::class);
        $driver->method('runExplain')
            ->willReturnOnConsecutiveCalls(...$explainResults);

        return $driver;
    }

    /**
     * Build a standard index synthesis result with given recommendations.
     *
     * @param  array<int, array<string, mixed>>  $recommendations
     * @return array<string, mixed>
     */
    private function makeIndexSynthesis(array $recommendations): array
    {
        return [
            'recommendations' => $recommendations,
            'existing_index_assessment' => [],
        ];
    }

    /**
     * Build a single recommendation array.
     *
     * @param  string[]  $columns
     * @param  string[]  $overlapsWith
     */
    private function makeRecommendation(
        string $table = 'users',
        array $columns = ['email', 'status'],
        string $type = 'composite',
        string $estimatedImprovement = 'high',
        ?string $ddl = null,
        string $rationale = 'ERS-ordered index for equality filters.',
        array $overlapsWith = [],
    ): array {
        $ddl ??= sprintf(
            'CREATE INDEX `idx_%s_%s` ON `%s` (%s);',
            $table,
            implode('_', $columns),
            $table,
            implode(', ', array_map(fn (string $c): string => "`{$c}`", $columns))
        );

        return [
            'table' => $table,
            'columns' => $columns,
            'type' => $type,
            'estimated_improvement' => $estimatedImprovement,
            'ddl' => $ddl,
            'rationale' => $rationale,
            'overlaps_with' => $overlapsWith,
        ];
    }

    // ---------------------------------------------------------------
    // 1. Environment guard: production -> disabled, returns enabled=false
    // ---------------------------------------------------------------

    public function test_environment_guard_production_returns_disabled(): void
    {
        $driver = $this->createMock(DriverInterface::class);
        $synthesis = $this->makeIndexSynthesis([$this->makeRecommendation()]);

        $result = $this->analyzer->analyze(
            'SELECT * FROM users WHERE email = "test@test.com"',
            $synthesis,
            $driver,
            'production',
        );

        $this->assertFalse($result['hypothetical_indexes']['enabled']);
        $this->assertEmpty($result['hypothetical_indexes']['simulations']);
        $this->assertNull($result['hypothetical_indexes']['best_recommendation']);
        $this->assertEmpty($result['findings']);
    }

    // ---------------------------------------------------------------
    // 2. Environment guard: local -> enabled
    // ---------------------------------------------------------------

    public function test_environment_guard_local_is_enabled(): void
    {
        $driver = $this->mockDriver([
            [['type' => 'ALL', 'rows' => 10000, 'key' => null]],
            [['type' => 'ref', 'rows' => 100, 'key' => 'idx_users_email_status']],
        ]);
        $synthesis = $this->makeIndexSynthesis([$this->makeRecommendation()]);

        $result = $this->analyzer->analyze(
            'SELECT * FROM users WHERE email = "test@test.com"',
            $synthesis,
            $driver,
            'local',
        );

        $this->assertTrue($result['hypothetical_indexes']['enabled']);
    }

    // ---------------------------------------------------------------
    // 3. Environment guard: testing -> enabled
    // ---------------------------------------------------------------

    public function test_environment_guard_testing_is_enabled(): void
    {
        $driver = $this->mockDriver([
            [['type' => 'ALL', 'rows' => 10000, 'key' => null]],
            [['type' => 'ref', 'rows' => 100, 'key' => 'idx_users_email_status']],
        ]);
        $synthesis = $this->makeIndexSynthesis([$this->makeRecommendation()]);

        $result = $this->analyzer->analyze(
            'SELECT * FROM users WHERE email = "test@test.com"',
            $synthesis,
            $driver,
            'testing',
        );

        $this->assertTrue($result['hypothetical_indexes']['enabled']);
    }

    // ---------------------------------------------------------------
    // 4. No recommendations -> empty simulations
    // ---------------------------------------------------------------

    public function test_no_recommendations_returns_empty_simulations(): void
    {
        $driver = $this->createMock(DriverInterface::class);
        $synthesis = $this->makeIndexSynthesis([]);

        $result = $this->analyzer->analyze(
            'SELECT * FROM users WHERE email = "test@test.com"',
            $synthesis,
            $driver,
            'local',
        );

        $this->assertTrue($result['hypothetical_indexes']['enabled']);
        $this->assertEmpty($result['hypothetical_indexes']['simulations']);
        $this->assertNull($result['hypothetical_indexes']['best_recommendation']);
        $this->assertEmpty($result['findings']);
    }

    // ---------------------------------------------------------------
    // 5. Null indexSynthesis -> empty simulations
    // ---------------------------------------------------------------

    public function test_null_index_synthesis_returns_empty_simulations(): void
    {
        $driver = $this->createMock(DriverInterface::class);

        $result = $this->analyzer->analyze(
            'SELECT * FROM users WHERE email = "test@test.com"',
            null,
            $driver,
            'local',
        );

        $this->assertTrue($result['hypothetical_indexes']['enabled']);
        $this->assertEmpty($result['hypothetical_indexes']['simulations']);
        $this->assertNull($result['hypothetical_indexes']['best_recommendation']);
    }

    // ---------------------------------------------------------------
    // 6. Significant improvement: access type goes from ALL -> ref
    // ---------------------------------------------------------------

    public function test_significant_improvement_access_type_all_to_ref(): void
    {
        $driver = $this->mockDriver([
            [['type' => 'ALL', 'rows' => 10000, 'key' => null]],
            [['type' => 'ref', 'rows' => 100, 'key' => 'idx_users_email_status']],
        ]);
        $synthesis = $this->makeIndexSynthesis([$this->makeRecommendation()]);

        $result = $this->analyzer->analyze(
            'SELECT * FROM users WHERE email = "test@test.com"',
            $synthesis,
            $driver,
            'local',
        );

        $simulations = $result['hypothetical_indexes']['simulations'];
        $this->assertCount(1, $simulations);
        $this->assertSame('significant', $simulations[0]['improvement']);
    }

    // ---------------------------------------------------------------
    // 7. Moderate improvement: rows reduced > 50%
    // ---------------------------------------------------------------

    public function test_moderate_improvement_rows_reduced_over_50_percent(): void
    {
        $driver = $this->mockDriver([
            [['type' => 'range', 'rows' => 10000, 'key' => null]],
            [['type' => 'range', 'rows' => 4000, 'key' => 'idx_users_email_status']],
        ]);
        $synthesis = $this->makeIndexSynthesis([$this->makeRecommendation()]);

        $result = $this->analyzer->analyze(
            'SELECT * FROM users WHERE email = "test@test.com"',
            $synthesis,
            $driver,
            'local',
        );

        $simulations = $result['hypothetical_indexes']['simulations'];
        $this->assertCount(1, $simulations);
        $this->assertSame('moderate', $simulations[0]['improvement']);
    }

    // ---------------------------------------------------------------
    // 8. Marginal improvement: rows reduced > 10% but <= 50%
    // ---------------------------------------------------------------

    public function test_marginal_improvement_rows_reduced_over_10_percent(): void
    {
        $driver = $this->mockDriver([
            [['type' => 'range', 'rows' => 10000, 'key' => null]],
            [['type' => 'range', 'rows' => 8000, 'key' => 'idx_users_email_status']],
        ]);
        $synthesis = $this->makeIndexSynthesis([$this->makeRecommendation()]);

        $result = $this->analyzer->analyze(
            'SELECT * FROM users WHERE email = "test@test.com"',
            $synthesis,
            $driver,
            'local',
        );

        $simulations = $result['hypothetical_indexes']['simulations'];
        $this->assertCount(1, $simulations);
        $this->assertSame('marginal', $simulations[0]['improvement']);
    }

    // ---------------------------------------------------------------
    // 9. No improvement: same access type and rows
    // ---------------------------------------------------------------

    public function test_no_improvement_same_access_type_and_rows(): void
    {
        $driver = $this->mockDriver([
            [['type' => 'ALL', 'rows' => 10000, 'key' => null]],
            [['type' => 'ALL', 'rows' => 10000, 'key' => null]],
        ]);
        $synthesis = $this->makeIndexSynthesis([$this->makeRecommendation()]);

        $result = $this->analyzer->analyze(
            'SELECT * FROM users WHERE email = "test@test.com"',
            $synthesis,
            $driver,
            'local',
        );

        $simulations = $result['hypothetical_indexes']['simulations'];
        $this->assertCount(1, $simulations);
        $this->assertSame('none', $simulations[0]['improvement']);
    }

    // ---------------------------------------------------------------
    // 10. Best recommendation selected correctly
    // ---------------------------------------------------------------

    public function test_best_recommendation_selected_correctly(): void
    {
        $rec1 = $this->makeRecommendation(
            table: 'users',
            columns: ['email'],
            ddl: 'CREATE INDEX `idx_users_email` ON `users` (`email`);',
        );
        $rec2 = $this->makeRecommendation(
            table: 'users',
            columns: ['status'],
            ddl: 'CREATE INDEX `idx_users_status` ON `users` (`status`);',
        );

        $driver = $this->mockDriver([
            // Rec 1 before: ALL
            [['type' => 'ALL', 'rows' => 10000, 'key' => null]],
            // Rec 1 after: same (no improvement)
            [['type' => 'ALL', 'rows' => 10000, 'key' => null]],
            // Rec 2 before: ALL
            [['type' => 'ALL', 'rows' => 10000, 'key' => null]],
            // Rec 2 after: ref (significant improvement)
            [['type' => 'ref', 'rows' => 50, 'key' => 'idx_users_status']],
        ]);

        $synthesis = $this->makeIndexSynthesis([$rec1, $rec2]);

        $result = $this->analyzer->analyze(
            'SELECT * FROM users WHERE status = 1',
            $synthesis,
            $driver,
            'local',
        );

        $this->assertSame(
            'CREATE INDEX `idx_users_status` ON `users` (`status`);',
            $result['hypothetical_indexes']['best_recommendation']
        );
    }

    // ---------------------------------------------------------------
    // 11. Max simulations limit respected
    // ---------------------------------------------------------------

    public function test_max_simulations_limit_respected(): void
    {
        $analyzer = new HypotheticalIndexAnalyzer(
            maxSimulations: 2,
            timeoutSeconds: 5,
            allowedEnvironments: ['local'],
            ddlExecutor: function (string $ddl): void {
                $this->executedDdl[] = $ddl;
            },
        );

        $rec1 = $this->makeRecommendation(columns: ['email'], ddl: 'CREATE INDEX `idx_a` ON `users` (`email`);');
        $rec2 = $this->makeRecommendation(columns: ['status'], ddl: 'CREATE INDEX `idx_b` ON `users` (`status`);');
        $rec3 = $this->makeRecommendation(columns: ['name'], ddl: 'CREATE INDEX `idx_c` ON `users` (`name`);');

        $driver = $this->mockDriver([
            [['type' => 'ALL', 'rows' => 10000, 'key' => null]],
            [['type' => 'ref', 'rows' => 100, 'key' => 'idx_a']],
            [['type' => 'ALL', 'rows' => 10000, 'key' => null]],
            [['type' => 'ref', 'rows' => 100, 'key' => 'idx_b']],
        ]);

        $synthesis = $this->makeIndexSynthesis([$rec1, $rec2, $rec3]);

        $result = $analyzer->analyze(
            'SELECT * FROM users WHERE email = "test"',
            $synthesis,
            $driver,
            'local',
        );

        $this->assertCount(2, $result['hypothetical_indexes']['simulations']);
    }

    // ---------------------------------------------------------------
    // 12. Validated flag: true when access type improves
    // ---------------------------------------------------------------

    public function test_validated_flag_true_when_access_type_improves(): void
    {
        $driver = $this->mockDriver([
            [['type' => 'ALL', 'rows' => 10000, 'key' => null]],
            [['type' => 'ref', 'rows' => 100, 'key' => 'idx_users_email_status']],
        ]);
        $synthesis = $this->makeIndexSynthesis([$this->makeRecommendation()]);

        $result = $this->analyzer->analyze(
            'SELECT * FROM users WHERE email = "test@test.com"',
            $synthesis,
            $driver,
            'local',
        );

        $simulations = $result['hypothetical_indexes']['simulations'];
        $this->assertTrue($simulations[0]['validated']);
    }

    // ---------------------------------------------------------------
    // 13. Validated flag: false when no improvement
    // ---------------------------------------------------------------

    public function test_validated_flag_false_when_no_improvement(): void
    {
        $driver = $this->mockDriver([
            [['type' => 'ALL', 'rows' => 10000, 'key' => null]],
            [['type' => 'ALL', 'rows' => 10000, 'key' => null]],
        ]);
        $synthesis = $this->makeIndexSynthesis([$this->makeRecommendation()]);

        $result = $this->analyzer->analyze(
            'SELECT * FROM users WHERE email = "test@test.com"',
            $synthesis,
            $driver,
            'local',
        );

        $simulations = $result['hypothetical_indexes']['simulations'];
        $this->assertFalse($simulations[0]['validated']);
    }

    // ---------------------------------------------------------------
    // 14. Finding generated for significant improvement
    // ---------------------------------------------------------------

    public function test_finding_generated_for_significant_improvement(): void
    {
        $driver = $this->mockDriver([
            [['type' => 'ALL', 'rows' => 10000, 'key' => null]],
            [['type' => 'ref', 'rows' => 100, 'key' => 'idx_users_email_status']],
        ]);
        $synthesis = $this->makeIndexSynthesis([$this->makeRecommendation()]);

        $result = $this->analyzer->analyze(
            'SELECT * FROM users WHERE email = "test@test.com"',
            $synthesis,
            $driver,
            'local',
        );

        $this->assertNotEmpty($result['findings']);
        $finding = $result['findings'][0];
        $this->assertInstanceOf(Finding::class, $finding);
        $this->assertSame(Severity::Warning, $finding->severity);
        $this->assertSame('hypothetical_index', $finding->category);
        $this->assertStringContainsString('significant', $finding->title);
    }

    // ---------------------------------------------------------------
    // 15. Finding generated for moderate improvement
    // ---------------------------------------------------------------

    public function test_finding_generated_for_moderate_improvement(): void
    {
        $driver = $this->mockDriver([
            [['type' => 'range', 'rows' => 10000, 'key' => null]],
            [['type' => 'range', 'rows' => 4000, 'key' => 'idx_users_email_status']],
        ]);
        $synthesis = $this->makeIndexSynthesis([$this->makeRecommendation()]);

        $result = $this->analyzer->analyze(
            'SELECT * FROM users WHERE email = "test@test.com"',
            $synthesis,
            $driver,
            'local',
        );

        $this->assertNotEmpty($result['findings']);
        $finding = $result['findings'][0];
        $this->assertSame(Severity::Optimization, $finding->severity);
        $this->assertStringContainsString('moderate', $finding->title);
    }

    // ---------------------------------------------------------------
    // 16. No finding for no improvement
    // ---------------------------------------------------------------

    public function test_no_finding_for_no_improvement(): void
    {
        $driver = $this->mockDriver([
            [['type' => 'ALL', 'rows' => 10000, 'key' => null]],
            [['type' => 'ALL', 'rows' => 10000, 'key' => null]],
        ]);
        $synthesis = $this->makeIndexSynthesis([$this->makeRecommendation()]);

        $result = $this->analyzer->analyze(
            'SELECT * FROM users WHERE email = "test@test.com"',
            $synthesis,
            $driver,
            'local',
        );

        $this->assertEmpty($result['findings']);
    }

    // ---------------------------------------------------------------
    // 17. DDL parsing: extract index name
    // ---------------------------------------------------------------

    public function test_ddl_parsing_extract_index_name(): void
    {
        $ddl = 'CREATE INDEX `idx_users_email_status` ON `users` (`email`, `status`);';
        $driver = $this->mockDriver([
            [['type' => 'ALL', 'rows' => 10000, 'key' => null]],
            [['type' => 'ref', 'rows' => 100, 'key' => 'idx_users_email_status']],
        ]);
        $synthesis = $this->makeIndexSynthesis([
            $this->makeRecommendation(ddl: $ddl),
        ]);

        $result = $this->analyzer->analyze(
            'SELECT * FROM users WHERE email = "test"',
            $synthesis,
            $driver,
            'local',
        );

        // Verify the DDL executor received both the CREATE and DROP statements
        $this->assertCount(2, $this->executedDdl);
        $this->assertSame($ddl, $this->executedDdl[0]);
        // DROP should contain the extracted index name
        $this->assertStringContainsString('idx_users_email_status', $this->executedDdl[1]);
    }

    // ---------------------------------------------------------------
    // 18. DDL parsing: extract table name for drop
    // ---------------------------------------------------------------

    public function test_ddl_parsing_extract_table_name_for_drop(): void
    {
        $ddl = 'CREATE INDEX `idx_orders_customer_id` ON `orders` (`customer_id`);';
        $driver = $this->mockDriver([
            [['type' => 'ALL', 'rows' => 5000, 'key' => null]],
            [['type' => 'ref', 'rows' => 10, 'key' => 'idx_orders_customer_id']],
        ]);
        $synthesis = $this->makeIndexSynthesis([
            $this->makeRecommendation(table: 'orders', columns: ['customer_id'], ddl: $ddl),
        ]);

        $result = $this->analyzer->analyze(
            'SELECT * FROM orders WHERE customer_id = 42',
            $synthesis,
            $driver,
            'local',
        );

        // Verify the DROP DDL references the correct table
        $this->assertCount(2, $this->executedDdl);
        $dropDdl = $this->executedDdl[1];
        $this->assertStringContainsString('DROP INDEX', $dropDdl);
        $this->assertStringContainsString('`orders`', $dropDdl);
        $this->assertStringContainsString('`idx_orders_customer_id`', $dropDdl);
    }

    // ---------------------------------------------------------------
    // 19. Custom allowed environments
    // ---------------------------------------------------------------

    public function test_custom_allowed_environments(): void
    {
        $analyzer = new HypotheticalIndexAnalyzer(
            maxSimulations: 3,
            timeoutSeconds: 5,
            allowedEnvironments: ['staging', 'ci'],
            ddlExecutor: function (string $ddl): void {
                $this->executedDdl[] = $ddl;
            },
        );

        $driver = $this->mockDriver([
            [['type' => 'ALL', 'rows' => 10000, 'key' => null]],
            [['type' => 'ref', 'rows' => 100, 'key' => 'idx_test']],
        ]);
        $synthesis = $this->makeIndexSynthesis([$this->makeRecommendation()]);

        // 'local' is NOT in custom allowed environments -> disabled
        $resultLocal = $analyzer->analyze(
            'SELECT * FROM users WHERE email = "test"',
            $synthesis,
            $driver,
            'local',
        );
        $this->assertFalse($resultLocal['hypothetical_indexes']['enabled']);

        // 'staging' IS in custom allowed environments -> enabled
        $driver2 = $this->mockDriver([
            [['type' => 'ALL', 'rows' => 10000, 'key' => null]],
            [['type' => 'ref', 'rows' => 100, 'key' => 'idx_test']],
        ]);
        $resultStaging = $analyzer->analyze(
            'SELECT * FROM users WHERE email = "test"',
            $synthesis,
            $driver2,
            'staging',
        );
        $this->assertTrue($resultStaging['hypothetical_indexes']['enabled']);
    }

    // ---------------------------------------------------------------
    // 20. Index drop always called even on explain error
    // ---------------------------------------------------------------

    public function test_index_drop_always_called_even_on_explain_error(): void
    {
        $ddl = 'CREATE INDEX `idx_users_email` ON `users` (`email`);';
        $driver = $this->createMock(DriverInterface::class);

        $callCount = 0;
        $driver->method('runExplain')
            ->willReturnCallback(function () use (&$callCount): array {
                $callCount++;
                if ($callCount === 1) {
                    // Before explain: succeeds
                    return [['type' => 'ALL', 'rows' => 10000, 'key' => null]];
                }
                // After explain: throws
                throw new \RuntimeException('Database error during EXPLAIN');
            });

        $synthesis = $this->makeIndexSynthesis([
            $this->makeRecommendation(ddl: $ddl),
        ]);

        $result = $this->analyzer->analyze(
            'SELECT * FROM users WHERE email = "test"',
            $synthesis,
            $driver,
            'local',
        );

        // Verify CREATE was called
        $this->assertStringContainsString('CREATE INDEX', $this->executedDdl[0]);
        // Verify DROP was still called despite error
        $this->assertCount(2, $this->executedDdl);
        $this->assertStringContainsString('DROP INDEX', $this->executedDdl[1]);
    }

    // ---------------------------------------------------------------
    // 21. Return structure has all required keys
    // ---------------------------------------------------------------

    public function test_return_structure_has_all_required_keys(): void
    {
        $driver = $this->mockDriver([
            [['type' => 'ALL', 'rows' => 10000, 'key' => null]],
            [['type' => 'ref', 'rows' => 100, 'key' => 'idx_test']],
        ]);
        $synthesis = $this->makeIndexSynthesis([$this->makeRecommendation()]);

        $result = $this->analyzer->analyze(
            'SELECT * FROM users WHERE email = "test"',
            $synthesis,
            $driver,
            'local',
        );

        $this->assertArrayHasKey('hypothetical_indexes', $result);
        $this->assertArrayHasKey('findings', $result);

        $hi = $result['hypothetical_indexes'];
        $this->assertArrayHasKey('enabled', $hi);
        $this->assertArrayHasKey('simulations', $hi);
        $this->assertArrayHasKey('best_recommendation', $hi);

        $this->assertIsBool($hi['enabled']);
        $this->assertIsArray($hi['simulations']);
        $this->assertIsArray($result['findings']);

        // Check simulation structure
        $sim = $hi['simulations'][0];
        $this->assertArrayHasKey('index_ddl', $sim);
        $this->assertArrayHasKey('before', $sim);
        $this->assertArrayHasKey('after', $sim);
        $this->assertArrayHasKey('improvement', $sim);
        $this->assertArrayHasKey('validated', $sim);
        $this->assertArrayHasKey('notes', $sim);

        // Check before/after structure
        $this->assertArrayHasKey('access_type', $sim['before']);
        $this->assertArrayHasKey('rows', $sim['before']);
        $this->assertArrayHasKey('key', $sim['before']);
        $this->assertArrayHasKey('access_type', $sim['after']);
        $this->assertArrayHasKey('rows', $sim['after']);
        $this->assertArrayHasKey('key', $sim['after']);
    }

    // ---------------------------------------------------------------
    // 22. Finding for marginal improvement has Info severity
    // ---------------------------------------------------------------

    public function test_finding_for_marginal_improvement_has_info_severity(): void
    {
        $driver = $this->mockDriver([
            [['type' => 'range', 'rows' => 10000, 'key' => null]],
            [['type' => 'range', 'rows' => 8000, 'key' => 'idx_test']],
        ]);
        $synthesis = $this->makeIndexSynthesis([$this->makeRecommendation()]);

        $result = $this->analyzer->analyze(
            'SELECT * FROM users WHERE email = "test"',
            $synthesis,
            $driver,
            'local',
        );

        $this->assertNotEmpty($result['findings']);
        $finding = $result['findings'][0];
        $this->assertSame(Severity::Info, $finding->severity);
        $this->assertStringContainsString('marginal', $finding->title);
    }

    // ---------------------------------------------------------------
    // 23. Before/after snapshots captured correctly
    // ---------------------------------------------------------------

    public function test_before_after_snapshots_captured_correctly(): void
    {
        $driver = $this->mockDriver([
            [['type' => 'ALL', 'rows' => 50000, 'key' => null]],
            [['type' => 'eq_ref', 'rows' => 1, 'key' => 'PRIMARY']],
        ]);
        $synthesis = $this->makeIndexSynthesis([$this->makeRecommendation()]);

        $result = $this->analyzer->analyze(
            'SELECT * FROM users WHERE id = 1',
            $synthesis,
            $driver,
            'local',
        );

        $sim = $result['hypothetical_indexes']['simulations'][0];
        $this->assertSame('ALL', $sim['before']['access_type']);
        $this->assertSame(50000, $sim['before']['rows']);
        $this->assertNull($sim['before']['key']);
        $this->assertSame('eq_ref', $sim['after']['access_type']);
        $this->assertSame(1, $sim['after']['rows']);
        $this->assertSame('PRIMARY', $sim['after']['key']);
    }

    // ---------------------------------------------------------------
    // 24. Notes describe access type change
    // ---------------------------------------------------------------

    public function test_notes_describe_access_type_change(): void
    {
        $driver = $this->mockDriver([
            [['type' => 'ALL', 'rows' => 10000, 'key' => null]],
            [['type' => 'ref', 'rows' => 500, 'key' => 'idx_test']],
        ]);
        $synthesis = $this->makeIndexSynthesis([$this->makeRecommendation()]);

        $result = $this->analyzer->analyze(
            'SELECT * FROM users WHERE email = "test"',
            $synthesis,
            $driver,
            'local',
        );

        $sim = $result['hypothetical_indexes']['simulations'][0];
        $this->assertStringContainsString('ALL', $sim['notes']);
        $this->assertStringContainsString('ref', $sim['notes']);
    }

    // ---------------------------------------------------------------
    // 25. Validated finding includes DDL as recommendation
    // ---------------------------------------------------------------

    public function test_validated_finding_includes_ddl_as_recommendation(): void
    {
        $ddl = 'CREATE INDEX `idx_users_email` ON `users` (`email`);';
        $driver = $this->mockDriver([
            [['type' => 'ALL', 'rows' => 10000, 'key' => null]],
            [['type' => 'ref', 'rows' => 100, 'key' => 'idx_users_email']],
        ]);
        $synthesis = $this->makeIndexSynthesis([
            $this->makeRecommendation(ddl: $ddl),
        ]);

        $result = $this->analyzer->analyze(
            'SELECT * FROM users WHERE email = "test"',
            $synthesis,
            $driver,
            'local',
        );

        $finding = $result['findings'][0];
        $this->assertSame($ddl, $finding->recommendation);
    }

    // ---------------------------------------------------------------
    // 26. Unvalidated finding has null recommendation
    // ---------------------------------------------------------------

    public function test_unvalidated_finding_has_null_recommendation(): void
    {
        // Marginal improvement with same access type (validated = false)
        $driver = $this->mockDriver([
            [['type' => 'range', 'rows' => 10000, 'key' => null]],
            [['type' => 'range', 'rows' => 8000, 'key' => 'idx_test']],
        ]);
        $synthesis = $this->makeIndexSynthesis([$this->makeRecommendation()]);

        $result = $this->analyzer->analyze(
            'SELECT * FROM users WHERE email = "test"',
            $synthesis,
            $driver,
            'local',
        );

        $this->assertNotEmpty($result['findings']);
        $finding = $result['findings'][0];
        // Same access type (range -> range), so validated=false, recommendation=null
        $this->assertNull($finding->recommendation);
    }

    // ---------------------------------------------------------------
    // 27. Finding metadata contains expected keys
    // ---------------------------------------------------------------

    public function test_finding_metadata_contains_expected_keys(): void
    {
        $driver = $this->mockDriver([
            [['type' => 'ALL', 'rows' => 10000, 'key' => null]],
            [['type' => 'ref', 'rows' => 100, 'key' => 'idx_test']],
        ]);
        $synthesis = $this->makeIndexSynthesis([$this->makeRecommendation()]);

        $result = $this->analyzer->analyze(
            'SELECT * FROM users WHERE email = "test"',
            $synthesis,
            $driver,
            'local',
        );

        $finding = $result['findings'][0];
        $this->assertArrayHasKey('index_ddl', $finding->metadata);
        $this->assertArrayHasKey('improvement', $finding->metadata);
        $this->assertArrayHasKey('validated', $finding->metadata);
        $this->assertArrayHasKey('before_access_type', $finding->metadata);
        $this->assertArrayHasKey('after_access_type', $finding->metadata);
        $this->assertArrayHasKey('before_rows', $finding->metadata);
        $this->assertArrayHasKey('after_rows', $finding->metadata);
    }

    // ---------------------------------------------------------------
    // 28. DDL without backticks parsed correctly
    // ---------------------------------------------------------------

    public function test_ddl_without_backticks_parsed_correctly(): void
    {
        $ddl = 'CREATE INDEX idx_users_name ON users (name);';
        $driver = $this->mockDriver([
            [['type' => 'ALL', 'rows' => 10000, 'key' => null]],
            [['type' => 'ref', 'rows' => 100, 'key' => 'idx_users_name']],
        ]);
        $synthesis = $this->makeIndexSynthesis([
            $this->makeRecommendation(ddl: $ddl),
        ]);

        $result = $this->analyzer->analyze(
            'SELECT * FROM users WHERE name = "John"',
            $synthesis,
            $driver,
            'local',
        );

        // Verify DROP DDL was generated from non-backtick CREATE DDL
        $this->assertCount(2, $this->executedDdl);
        $dropDdl = $this->executedDdl[1];
        $this->assertStringContainsString('DROP INDEX', $dropDdl);
        $this->assertStringContainsString('idx_users_name', $dropDdl);
        $this->assertStringContainsString('users', $dropDdl);
    }
}
