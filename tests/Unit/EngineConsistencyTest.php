<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QuerySentinel\Analyzers\IndexSynthesisAnalyzer;
use QuerySentinel\Enums\ComplexityClass;
use QuerySentinel\Enums\Severity;
use QuerySentinel\Scoring\ConfidenceScorer;
use QuerySentinel\Support\EngineConsistencyValidator;
use QuerySentinel\Support\ExecutionProfile;
use QuerySentinel\Support\Finding;

/**
 * Tests for the 6 engine correctness violations and their fixes.
 *
 * Covers: access-type guards, context-aware finding generation,
 * suppression layer, deduplication layer, consistency validation,
 * and status-score alignment.
 */
final class EngineConsistencyTest extends TestCase
{
    // ---------------------------------------------------------------
    // Violation 1: IndexSynthesisAnalyzer — access-type guard
    // ---------------------------------------------------------------

    public function test_const_access_no_index_synthesis(): void
    {
        $analyzer = new IndexSynthesisAnalyzer;
        $result = $analyzer->analyze(
            'SELECT * FROM users WHERE id = 1',
            ['primary_access_type' => 'const_row', 'tables_accessed' => ['users']],
            null,
            null,
        );

        $this->assertEmpty($result['index_synthesis']['recommendations']);
        $this->assertEmpty($result['findings']);
    }

    public function test_eq_ref_access_no_index_synthesis(): void
    {
        $analyzer = new IndexSynthesisAnalyzer;
        $result = $analyzer->analyze(
            'SELECT * FROM users WHERE email = ?',
            ['primary_access_type' => 'single_row_lookup', 'tables_accessed' => ['users']],
            null,
            null,
        );

        $this->assertEmpty($result['index_synthesis']['recommendations']);
        $this->assertEmpty($result['findings']);
    }

    public function test_zero_row_const_no_index_synthesis(): void
    {
        $analyzer = new IndexSynthesisAnalyzer;
        $result = $analyzer->analyze(
            'SELECT * FROM users WHERE id = 0',
            ['primary_access_type' => 'zero_row_const', 'tables_accessed' => ['users']],
            null,
            null,
        );

        $this->assertEmpty($result['index_synthesis']['recommendations']);
        $this->assertEmpty($result['findings']);
    }

    // ---------------------------------------------------------------
    // Violation 5: ConfidenceScorer — access-type aware sample size
    // ---------------------------------------------------------------

    public function test_const_access_confidence_high(): void
    {
        $scorer = new ConfidenceScorer;
        $result = $scorer->score(
            [
                'primary_access_type' => 'const_row',
                'per_table_estimates' => [['actual_rows' => 1, 'loops' => 1]],
                'tables_accessed' => ['users'],
                'join_count' => 0,
            ],
            null,
            null,
            null,
            true,
        );

        $sampleFactor = null;
        foreach ($result['confidence']['factors'] as $factor) {
            if ($factor['name'] === 'sample_size') {
                $sampleFactor = $factor;
                break;
            }
        }

        $this->assertNotNull($sampleFactor);
        $this->assertSame(1.0, $sampleFactor['score']);
        $this->assertStringContainsString('deterministic', $sampleFactor['note']);
    }

    public function test_table_scan_confidence_low_sample(): void
    {
        $scorer = new ConfidenceScorer;
        $result = $scorer->score(
            [
                'primary_access_type' => 'table_scan',
                'per_table_estimates' => [['actual_rows' => 5, 'loops' => 1]],
                'tables_accessed' => ['users'],
                'join_count' => 0,
            ],
            null,
            null,
            null,
            true,
        );

        $sampleFactor = null;
        foreach ($result['confidence']['factors'] as $factor) {
            if ($factor['name'] === 'sample_size') {
                $sampleFactor = $factor;
                break;
            }
        }

        $this->assertNotNull($sampleFactor);
        $this->assertLessThan(0.1, $sampleFactor['score']);
    }

    // ---------------------------------------------------------------
    // EngineConsistencyValidator — contradiction detection
    // ---------------------------------------------------------------

    public function test_validator_access_type_contradiction(): void
    {
        $validator = new EngineConsistencyValidator;
        $result = $validator->validate(
            ['primary_access_type' => 'index_lookup', 'is_index_backed' => false],
            [],
        );

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['violations']);
        $this->assertStringContainsString('primary_access_type=index_lookup', $result['violations'][0]);
    }

    public function test_validator_table_scan_contradiction(): void
    {
        $validator = new EngineConsistencyValidator;
        $result = $validator->validate(
            ['primary_access_type' => 'index_lookup', 'has_table_scan' => true, 'is_index_backed' => true],
            [],
        );

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('has_table_scan=true', $result['violations'][0]);
    }

    public function test_validator_risk_contradiction(): void
    {
        $validator = new EngineConsistencyValidator;
        $result = $validator->validate(
            ['complexity_risk' => 'LOW', 'has_table_scan' => true, 'current_rows' => 5000],
            [],
        );

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('complexity_risk=LOW', $result['violations'][0]);
    }

    public function test_validator_duplicate_findings(): void
    {
        $validator = new EngineConsistencyValidator;
        $finding = new Finding(
            severity: Severity::Warning,
            category: 'rule',
            title: 'No index used',
            description: 'Query does not use an index.',
            recommendation: 'Add an index.',
        );

        $result = $validator->validate(
            ['primary_access_type' => 'table_scan', 'has_table_scan' => true],
            [$finding, $finding],
        );

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Duplicate finding', $result['violations'][0]);
    }

    public function test_validator_clean_output(): void
    {
        $validator = new EngineConsistencyValidator;
        $result = $validator->validate(
            [
                'primary_access_type' => 'index_lookup',
                'is_index_backed' => true,
                'has_table_scan' => false,
                'complexity_risk' => 'LOW',
            ],
            [
                new Finding(Severity::Info, 'explain_why', 'Index choice: idx_email', 'Chose idx_email.'),
                new Finding(Severity::Optimization, 'complexity', 'Scan: O(log n)', 'Logarithmic scan.'),
            ],
        );

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['violations']);
    }

    // ---------------------------------------------------------------
    // Engine suppression layer — suppressForOptimalAccess()
    // ---------------------------------------------------------------

    public function test_suppression_removes_index_findings_for_const(): void
    {
        $findings = [
            new Finding(Severity::Warning, 'index_synthesis', 'Missing composite index', 'Should add index.', 'CREATE INDEX ...'),
            new Finding(Severity::Warning, 'rule', 'No index used', 'Query has no index.', 'Add an index.'),
            new Finding(Severity::Info, 'explain_why', 'Index choice: PRIMARY', 'Uses primary key.'),
        ];

        $filtered = $this->callSuppressForOptimalAccess(
            $findings,
            ['primary_access_type' => 'const_row'],
            'SELECT * FROM users WHERE id = 1',
        );

        // index_synthesis and index rule findings removed, explain_why kept
        $this->assertCount(1, $filtered);
        $this->assertSame('explain_why', $filtered[0]->category);
    }

    public function test_suppression_keeps_anti_pattern_for_const(): void
    {
        $findings = [
            new Finding(Severity::Warning, 'anti_pattern', 'SELECT * anti-pattern', 'Use explicit columns.', 'Replace SELECT * with column names.'),
            new Finding(Severity::Warning, 'index_synthesis', 'Missing index', 'Needs index.', 'CREATE INDEX ...'),
        ];

        $filtered = $this->callSuppressForOptimalAccess(
            $findings,
            ['primary_access_type' => 'const_row'],
            'SELECT * FROM users WHERE id = 1',
        );

        $this->assertCount(1, $filtered);
        $this->assertSame('anti_pattern', $filtered[0]->category);
    }

    public function test_no_order_by_suppresses_sort_recommendation(): void
    {
        $findings = [
            new Finding(Severity::Optimization, 'complexity', 'Sort complexity: O(n log n)', 'Filesort needed.', 'Extend index to include ORDER BY columns.'),
            new Finding(Severity::Info, 'explain_why', 'What this query does', 'Selects from users.'),
        ];

        $filtered = $this->callSuppressForOptimalAccess(
            $findings,
            ['primary_access_type' => 'const_row'],
            'SELECT * FROM users WHERE id = 1',
        );

        // ORDER BY recommendation suppressed for CONST access + no ORDER BY in SQL
        $categories = array_map(fn (Finding $f) => $f->category, $filtered);
        $this->assertNotContains('complexity', $categories);
    }

    // ---------------------------------------------------------------
    // Engine deduplication layer — deduplicateFindings()
    // ---------------------------------------------------------------

    public function test_dedup_identical_recommendations(): void
    {
        $findings = [
            new Finding(Severity::Warning, 'rule', 'No index used', 'No index.', 'Add an index on users(email).'),
            new Finding(Severity::Critical, 'index_synthesis', 'Missing index', 'Needs index.', 'Add an index on users(email).'),
            new Finding(Severity::Info, 'explain_why', 'Info', 'Some info.'),
        ];

        $deduped = $this->callDeduplicateFindings($findings);

        $actionable = array_filter($deduped, fn (Finding $f) => $f->recommendation !== null);
        $recommendations = array_map(fn (Finding $f) => $f->recommendation, $actionable);
        $uniqueRecs = array_unique($recommendations);

        // Same recommendation text → only one survives
        $this->assertCount(1, $uniqueRecs);

        // The surviving one should be the highest severity (Critical)
        $surviving = array_values($actionable)[0];
        $this->assertSame(Severity::Critical, $surviving->severity);
    }

    public function test_dedup_no_index_vs_index_synthesis(): void
    {
        $findings = [
            new Finding(Severity::Warning, 'rule', 'No index used', 'No index.', 'Add an index.', ['tables_accessed' => ['users']]),
            new Finding(Severity::Optimization, 'index_synthesis', 'Composite index recommendation', 'Better index.', 'CREATE INDEX idx_users_email ON users(email).', ['table' => 'users']),
        ];

        $deduped = $this->callDeduplicateFindings($findings);

        // NoIndexRule removed because IndexSynthesis covers same table
        $categories = array_map(fn (Finding $f) => $f->category, $deduped);
        $this->assertNotContains('rule', $categories);
        $this->assertContains('index_synthesis', $categories);
    }

    // ---------------------------------------------------------------
    // Violation 2: ORDER BY guard in generateComplexityFindings
    // ---------------------------------------------------------------

    public function test_no_order_by_no_sort_finding(): void
    {
        $profile = new ExecutionProfile(
            nestedLoopDepth: 0,
            joinFanouts: [],
            btreeDepths: [],
            logicalReads: 10,
            physicalReads: 0,
            scanComplexity: ComplexityClass::Logarithmic,
            sortComplexity: ComplexityClass::Linearithmic,
        );

        $findings = $this->callGenerateComplexityFindings($profile, 'SELECT * FROM users WHERE id = 1');

        $sortFindings = array_filter($findings, fn (Finding $f) => $f->recommendation !== null && stripos($f->recommendation, 'ORDER BY') !== false);

        $this->assertEmpty($sortFindings, 'Sort recommendation should not appear when query has no ORDER BY.');
    }

    // ---------------------------------------------------------------
    // Violation 6: SELECT * → no covering index suggestion
    // ---------------------------------------------------------------

    public function test_select_star_no_covering_index_recommendation(): void
    {
        $metrics = [
            'has_table_scan' => false,
            'has_weedout' => false,
            'has_filesort' => false,
            'has_covering_index' => false,
            'is_index_backed' => true,
        ];

        $topRec = $this->callIdentifyTopRecommendation($metrics, null, null, 'SELECT * FROM users WHERE email = ?');

        $this->assertNotNull($topRec);
        $this->assertStringContainsString('SELECT *', $topRec);
        $this->assertStringContainsString('explicit column', $topRec);
        $this->assertStringNotContainsString('Extend the index to include SELECT columns', $topRec);
    }

    // ---------------------------------------------------------------
    // Violation 6 counterpart: non-SELECT * gets covering index suggestion
    // ---------------------------------------------------------------

    public function test_explicit_columns_gets_covering_index_recommendation(): void
    {
        $metrics = [
            'has_table_scan' => false,
            'has_weedout' => false,
            'has_filesort' => false,
            'has_covering_index' => false,
            'is_index_backed' => true,
        ];

        $topRec = $this->callIdentifyTopRecommendation($metrics, null, null, 'SELECT id, name FROM users WHERE email = ?');

        $this->assertNotNull($topRec);
        $this->assertStringContainsString('covering-index', $topRec);
    }

    // ---------------------------------------------------------------
    // Helpers: call private Engine methods via reflection
    // ---------------------------------------------------------------

    /**
     * @param  Finding[]  $findings
     * @param  array<string, mixed>  $metrics
     * @return Finding[]
     */
    private function callSuppressForOptimalAccess(array $findings, array $metrics, string $rawSql): array
    {
        $engine = $this->createMinimalEngine();
        $method = new \ReflectionMethod($engine, 'suppressForOptimalAccess');

        return $method->invoke($engine, $findings, $metrics, $rawSql);
    }

    /**
     * @param  Finding[]  $findings
     * @return Finding[]
     */
    private function callDeduplicateFindings(array $findings): array
    {
        $engine = $this->createMinimalEngine();
        $method = new \ReflectionMethod($engine, 'deduplicateFindings');

        return $method->invoke($engine, $findings);
    }

    /**
     * @return Finding[]
     */
    private function callGenerateComplexityFindings(ExecutionProfile $profile, string $rawSql): array
    {
        $engine = $this->createMinimalEngine();
        $method = new \ReflectionMethod($engine, 'generateComplexityFindings');

        return $method->invoke($engine, $profile, $rawSql);
    }

    private function callIdentifyTopRecommendation(array $metrics, ?ExecutionProfile $profile, ?array $stability, string $rawSql, array $rootCauses = []): ?string
    {
        $engine = $this->createMinimalEngine();
        $method = new \ReflectionMethod($engine, 'identifyTopRecommendation');

        return $method->invoke($engine, $metrics, $profile, $stability, $rawSql, $rootCauses);
    }

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

    // ---------------------------------------------------------------
    // Validator: lock_scope for plain SELECT
    // ---------------------------------------------------------------

    public function test_validator_lock_scope_for_select(): void
    {
        $validator = new EngineConsistencyValidator;

        // lock_scope='table' for a plain SELECT = contradiction
        $result = $validator->validate(
            ['primary_access_type' => 'table_scan', 'has_table_scan' => true],
            [],
            ['lock_scope' => 'table'],
            'SELECT * FROM users WHERE name = "test"',
        );

        $this->assertFalse($result['valid']);
        $lockViolation = false;
        foreach ($result['violations'] as $v) {
            if (str_contains($v, 'lock_scope=table') && str_contains($v, 'plain SELECT')) {
                $lockViolation = true;
                break;
            }
        }
        $this->assertTrue($lockViolation, 'Expected lock_scope contradiction for plain SELECT');
    }

    // ---------------------------------------------------------------
    // Validator: lock_scope for SELECT FOR UPDATE is OK
    // ---------------------------------------------------------------

    public function test_validator_lock_scope_for_locking_read_ok(): void
    {
        $validator = new EngineConsistencyValidator;

        // lock_scope='row' for a SELECT FOR UPDATE = valid (no contradiction)
        $result = $validator->validate(
            ['primary_access_type' => 'const_row', 'is_index_backed' => true, 'has_table_scan' => false],
            [],
            ['lock_scope' => 'row'],
            'SELECT * FROM users WHERE id = 1 FOR UPDATE',
        );

        // Should not have lock_scope violation (FOR UPDATE is a locking read)
        $hasLockViolation = false;
        foreach ($result['violations'] as $v) {
            if (str_contains($v, 'plain SELECT')) {
                $hasLockViolation = true;
                break;
            }
        }
        $this->assertFalse($hasLockViolation, 'FOR UPDATE should not trigger plain SELECT lock_scope contradiction');
    }

    // ---------------------------------------------------------------
    // Root-cause detection: function on column
    // ---------------------------------------------------------------

    public function test_root_cause_detection_function_on_column(): void
    {
        $engine = $this->createMinimalEngine();
        $method = new \ReflectionMethod($engine, 'detectRootCauses');

        $findings = [
            new Finding(
                severity: Severity::Warning,
                category: 'anti_pattern',
                title: 'Function wrapping on column',
                description: 'LOWER() prevents index usage.',
                recommendation: 'Remove function wrapping.',
                metadata: ['function' => 'LOWER', 'column' => 'email'],
            ),
        ];

        $metrics = ['has_table_scan' => true, 'primary_access_type' => 'table_scan'];

        $rootCauses = $method->invoke($engine, $findings, $metrics, 'SELECT * FROM users WHERE LOWER(email) = "test"');

        $this->assertContains('function_on_column', $rootCauses);
        $this->assertNotContains('missing_index', $rootCauses);
    }

    // ---------------------------------------------------------------
    // Root-cause suppression removes generic index findings
    // ---------------------------------------------------------------

    public function test_root_cause_suppression(): void
    {
        $engine = $this->createMinimalEngine();
        $method = new \ReflectionMethod($engine, 'suppressByRootCause');

        $findings = [
            new Finding(Severity::Warning, 'no_index', 'No index used', 'No index.', 'Add an index.'),
            new Finding(Severity::Warning, 'full_table_scan', 'Full table scan', 'Scanning all rows.', 'Add a composite index.'),
            new Finding(Severity::Warning, 'anti_pattern', 'Function wrapping', 'LOWER() used.', 'Remove LOWER().', ['function' => 'LOWER']),
        ];

        $filtered = $method->invoke($engine, $findings, ['function_on_column'], 'SELECT * FROM users WHERE LOWER(email) = "test"');

        // no_index and full_table_scan suppressed; anti_pattern kept
        $this->assertCount(1, $filtered);
        $this->assertSame('anti_pattern', $filtered[0]->category);
    }

    // ---------------------------------------------------------------
    // Root-cause: missing_index keeps generic findings
    // ---------------------------------------------------------------

    public function test_root_cause_missing_index_keeps_generics(): void
    {
        $engine = $this->createMinimalEngine();
        $method = new \ReflectionMethod($engine, 'suppressByRootCause');

        $findings = [
            new Finding(Severity::Warning, 'no_index', 'No index used', 'No index.', 'Add an index.'),
            new Finding(Severity::Warning, 'full_table_scan', 'Full table scan', 'Scanning all rows.', 'Add a composite index.'),
        ];

        // missing_index root cause: no suppression (generic index recs are appropriate)
        $filtered = $method->invoke($engine, $findings, ['missing_index'], 'SELECT * FROM users WHERE name = "test"');

        $this->assertCount(2, $filtered);
    }

    // ---------------------------------------------------------------
    // Top recommendation for function wrapping
    // ---------------------------------------------------------------

    public function test_top_recommendation_for_function_wrapping(): void
    {
        $engine = $this->createMinimalEngine();
        $method = new \ReflectionMethod($engine, 'identifyTopRecommendation');

        $metrics = [
            'has_table_scan' => true,
            'has_weedout' => false,
            'has_filesort' => false,
            'has_covering_index' => false,
            'is_index_backed' => false,
        ];

        $topRec = $method->invoke($engine, $metrics, null, null, 'SELECT * FROM users WHERE LOWER(email) = "test@example.com"', ['function_on_column']);

        $this->assertNotNull($topRec);
        $this->assertStringContainsString('LOWER()', $topRec);
        $this->assertStringContainsString('functional index', $topRec);
        $this->assertStringNotContainsString('Add an index on the scanned table', $topRec);
    }
}
