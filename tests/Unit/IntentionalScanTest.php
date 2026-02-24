<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QuerySentinel\Analyzers\IndexSynthesisAnalyzer;
use QuerySentinel\Enums\ComplexityClass;
use QuerySentinel\Enums\Severity;
use QuerySentinel\Rules\FullTableScanRule;
use QuerySentinel\Rules\NoIndexRule;
use QuerySentinel\Scoring\DefaultScoringEngine;
use QuerySentinel\Support\EngineConsistencyValidator;
use QuerySentinel\Support\Finding;
use QuerySentinel\Support\SqlParser;

/**
 * Tests for intent-aware scan classification.
 *
 * Ensures the engine distinguishes between:
 * - Pathological full scan (missing index, WHERE exists)
 * - Intentional full scan (no WHERE/JOIN/GROUP BY/HAVING/ORDER BY)
 */
final class IntentionalScanTest extends TestCase
{
    // ---------------------------------------------------------------
    // SqlParser::isIntentionalFullScan() detection
    // ---------------------------------------------------------------

    public function test_select_without_clauses_is_intentional(): void
    {
        $this->assertTrue(SqlParser::isIntentionalFullScan('SELECT id, name FROM users'));
        $this->assertTrue(SqlParser::isIntentionalFullScan('SELECT * FROM users'));
        $this->assertTrue(SqlParser::isIntentionalFullScan('  select id from users  '));
        $this->assertTrue(SqlParser::isIntentionalFullScan('SELECT id, name, email FROM users LIMIT 100'));
    }

    public function test_select_with_where_is_not_intentional(): void
    {
        $this->assertFalse(SqlParser::isIntentionalFullScan('SELECT id FROM users WHERE active = 1'));
        $this->assertFalse(SqlParser::isIntentionalFullScan('SELECT * FROM users WHERE LOWER(email) = "test"'));
    }

    public function test_select_with_join_is_not_intentional(): void
    {
        $this->assertFalse(SqlParser::isIntentionalFullScan('SELECT u.id FROM users u JOIN orders o ON u.id = o.user_id'));
        $this->assertFalse(SqlParser::isIntentionalFullScan('SELECT * FROM users LEFT JOIN profiles ON users.id = profiles.user_id'));
    }

    public function test_select_with_group_by_is_not_intentional(): void
    {
        $this->assertFalse(SqlParser::isIntentionalFullScan('SELECT status, COUNT(*) FROM users GROUP BY status'));
    }

    public function test_select_with_having_is_not_intentional(): void
    {
        $this->assertFalse(SqlParser::isIntentionalFullScan('SELECT status, COUNT(*) c FROM users GROUP BY status HAVING c > 1'));
    }

    public function test_select_with_order_by_is_not_intentional(): void
    {
        $this->assertFalse(SqlParser::isIntentionalFullScan('SELECT * FROM users ORDER BY created_at DESC'));
    }

    public function test_update_is_not_intentional(): void
    {
        $this->assertFalse(SqlParser::isIntentionalFullScan('UPDATE users SET active = 0'));
        $this->assertFalse(SqlParser::isIntentionalFullScan('DELETE FROM users'));
    }

    // ---------------------------------------------------------------
    // Rule suppression for intentional scans
    // ---------------------------------------------------------------

    public function test_intentional_scan_no_index_rule_suppressed(): void
    {
        $rule = new NoIndexRule;
        $metrics = [
            'has_table_scan' => true,
            'primary_access_type' => 'table_scan',
            'is_index_backed' => false,
            'indexes_used' => [],
            'rows_examined' => 100,
            'is_zero_row_const' => false,
            'is_intentional_scan' => true,
        ];

        $this->assertNull($rule->evaluate($metrics));
    }

    public function test_intentional_scan_full_table_scan_rule_suppressed(): void
    {
        $rule = new FullTableScanRule;
        $metrics = [
            'has_table_scan' => true,
            'rows_examined' => 50000,
            'is_intentional_scan' => true,
        ];

        $this->assertNull($rule->evaluate($metrics));
    }

    public function test_pathological_scan_still_fires_rules(): void
    {
        $noIndexRule = new NoIndexRule;
        $fullScanRule = new FullTableScanRule;

        $metrics = [
            'has_table_scan' => true,
            'primary_access_type' => 'table_scan',
            'is_index_backed' => false,
            'indexes_used' => [],
            'rows_examined' => 50000,
            'is_zero_row_const' => false,
            // NOT intentional — has WHERE clause context
        ];

        $this->assertNotNull($noIndexRule->evaluate($metrics));
        $this->assertNotNull($fullScanRule->evaluate($metrics));
    }

    // ---------------------------------------------------------------
    // Scoring neutralization for intentional scans
    // ---------------------------------------------------------------

    public function test_intentional_scan_index_quality_score_100(): void
    {
        $engine = new DefaultScoringEngine;
        $metrics = [
            'has_table_scan' => true,
            'primary_access_type' => 'table_scan',
            'is_index_backed' => false,
            'has_covering_index' => false,
            'has_index_merge' => false,
            'execution_time_ms' => 0.5,
            'rows_examined' => 100,
            'rows_returned' => 100,
            'nested_loop_depth' => 0,
            'fanout_factor' => 1.0,
            'has_weedout' => false,
            'has_temp_table' => false,
            'has_early_termination' => false,
            'complexity' => ComplexityClass::Linear->value,
            'is_intentional_scan' => true,
        ];

        $result = $engine->score($metrics);

        // index_quality should be 100 (not 30)
        $this->assertSame(100, $result['breakdown']['index_quality']['score']);
    }

    public function test_intentional_scan_scalability_score_100(): void
    {
        $engine = new DefaultScoringEngine;
        $metrics = [
            'has_table_scan' => true,
            'primary_access_type' => 'table_scan',
            'is_index_backed' => false,
            'has_covering_index' => false,
            'has_index_merge' => false,
            'execution_time_ms' => 0.5,
            'rows_examined' => 100,
            'rows_returned' => 100,
            'nested_loop_depth' => 0,
            'fanout_factor' => 1.0,
            'has_weedout' => false,
            'has_temp_table' => false,
            'has_early_termination' => false,
            'complexity' => ComplexityClass::Linear->value,
            'is_intentional_scan' => true,
        ];

        $result = $engine->score($metrics);

        // scalability should be 100 (not 50 for linear)
        $this->assertSame(100, $result['breakdown']['scalability']['score']);
    }

    public function test_intentional_scan_composite_score_high(): void
    {
        $engine = new DefaultScoringEngine;
        $metrics = [
            'has_table_scan' => true,
            'primary_access_type' => 'table_scan',
            'is_index_backed' => false,
            'has_covering_index' => false,
            'has_index_merge' => false,
            'execution_time_ms' => 0.5,
            'rows_examined' => 100,
            'rows_returned' => 100,
            'nested_loop_depth' => 0,
            'fanout_factor' => 1.0,
            'has_weedout' => false,
            'has_temp_table' => false,
            'has_early_termination' => false,
            'complexity' => ComplexityClass::Linear->value,
            'is_intentional_scan' => true,
        ];

        $result = $engine->score($metrics);

        // Score must not drop below 95 solely due to intentional full scan
        $this->assertGreaterThanOrEqual(95.0, $result['composite_score']);
    }

    // ---------------------------------------------------------------
    // Engine pipeline: root-cause + suppression + top recommendation
    // ---------------------------------------------------------------

    public function test_intentional_scan_root_cause(): void
    {
        $engine = $this->createMinimalEngine();
        $method = new \ReflectionMethod($engine, 'detectRootCauses');

        $findings = [];
        $metrics = ['has_table_scan' => true, 'primary_access_type' => 'table_scan', 'is_intentional_scan' => true];

        $rootCauses = $method->invoke($engine, $findings, $metrics, 'SELECT id, name FROM users');

        $this->assertSame(['intentional_scan'], $rootCauses);
    }

    public function test_intentional_scan_suppresses_generic_index_findings(): void
    {
        $engine = $this->createMinimalEngine();
        $method = new \ReflectionMethod($engine, 'suppressByRootCause');

        $findings = [
            new Finding(Severity::Critical, 'no_index', 'No index used', 'No index.', 'Add an index.'),
            new Finding(Severity::Warning, 'full_table_scan', 'Full table scan detected', 'Scanning rows.', 'Add a composite index.'),
            new Finding(Severity::Warning, 'anti_pattern', 'SELECT * detected', 'Use explicit columns.', 'Specify columns.'),
        ];

        $filtered = $method->invoke($engine, $findings, ['intentional_scan'], 'SELECT * FROM users');

        // no_index and full_table_scan suppressed; anti_pattern kept
        $this->assertCount(1, $filtered);
        $this->assertSame('anti_pattern', $filtered[0]->category);
    }

    public function test_intentional_scan_top_recommendation(): void
    {
        $engine = $this->createMinimalEngine();
        $method = new \ReflectionMethod($engine, 'identifyTopRecommendation');

        $metrics = [
            'has_table_scan' => true,
            'has_weedout' => false,
            'has_filesort' => false,
            'has_covering_index' => false,
            'is_index_backed' => false,
            'is_intentional_scan' => true,
        ];

        $topRec = $method->invoke($engine, $metrics, null, null, 'SELECT id, name FROM users', ['intentional_scan']);

        $this->assertNotNull($topRec);
        $this->assertStringContainsString('entire dataset by design', $topRec);
        $this->assertStringContainsString('LIMIT', $topRec);
        $this->assertStringNotContainsString('Add an index', $topRec);
    }

    // ---------------------------------------------------------------
    // IndexSynthesisAnalyzer suppression
    // ---------------------------------------------------------------

    public function test_intentional_scan_index_synthesis_suppressed(): void
    {
        $analyzer = new IndexSynthesisAnalyzer;
        $result = $analyzer->analyze(
            'SELECT id, name FROM users',
            ['primary_access_type' => 'table_scan', 'tables_accessed' => ['users'], 'is_intentional_scan' => true],
            null,
            null,
        );

        $this->assertEmpty($result['index_synthesis']['recommendations']);
        $this->assertEmpty($result['findings']);
    }

    // ---------------------------------------------------------------
    // EngineConsistencyValidator: intentional scan is not a contradiction
    // ---------------------------------------------------------------

    public function test_validator_allows_low_risk_intentional_scan(): void
    {
        $validator = new EngineConsistencyValidator;
        $result = $validator->validate(
            [
                'primary_access_type' => 'table_scan',
                'has_table_scan' => true,
                'is_index_backed' => false,
                'complexity_risk' => 'LOW',
                'current_rows' => 50000,
                'is_intentional_scan' => true,
            ],
            [],
        );

        // No Rule 3 violation: intentional scan with LOW risk is legitimate
        $hasRiskViolation = false;
        foreach ($result['violations'] as $v) {
            if (str_contains($v, 'complexity_risk=LOW')) {
                $hasRiskViolation = true;
            }
        }
        $this->assertFalse($hasRiskViolation, 'Intentional scan should not trigger risk contradiction');
    }

    public function test_pathological_scan_still_triggers_validator(): void
    {
        $validator = new EngineConsistencyValidator;
        $result = $validator->validate(
            [
                'primary_access_type' => 'table_scan',
                'has_table_scan' => true,
                'is_index_backed' => false,
                'complexity_risk' => 'LOW',
                'current_rows' => 50000,
                // NOT intentional
            ],
            [],
        );

        $this->assertFalse($result['valid']);
        $hasRiskViolation = false;
        foreach ($result['violations'] as $v) {
            if (str_contains($v, 'complexity_risk=LOW')) {
                $hasRiskViolation = true;
            }
        }
        $this->assertTrue($hasRiskViolation);
    }

    // ---------------------------------------------------------------
    // Helper: create minimal Engine for reflection testing
    // ---------------------------------------------------------------

    private function createMinimalEngine(): \QuerySentinel\Core\Engine
    {
        return new \QuerySentinel\Core\Engine(
            analyzer: new class implements \QuerySentinel\Contracts\AnalyzerInterface
            {
                public function analyze(string $sql, string $mode = 'sql'): \QuerySentinel\Support\Report
                {
                    throw new \RuntimeException('Not used in unit tests.');
                }
            },
            guard: new \QuerySentinel\Support\ExecutionGuard,
            sanitizer: new \QuerySentinel\Support\SqlSanitizer,
        );
    }
}
