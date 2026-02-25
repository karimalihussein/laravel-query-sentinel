<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QuerySentinel\Analyzers\AntiPatternAnalyzer;
use QuerySentinel\Enums\Severity;

final class AntiPatternAnalyzerTest extends TestCase
{
    private AntiPatternAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new AntiPatternAnalyzer;
    }

    // ---------------------------------------------------------------
    // 1. SELECT * detection
    // ---------------------------------------------------------------

    public function test_select_star_detected(): void
    {
        $sql = 'SELECT * FROM users WHERE id = 1';
        $result = $this->analyzer->analyze($sql, []);

        $this->assertPatternFound($result, 'select_star');
        $this->assertFindingExists($result, 'SELECT * detected', Severity::Warning);
    }

    // ---------------------------------------------------------------
    // 2. No SELECT * — no finding
    // ---------------------------------------------------------------

    public function test_no_select_star_no_finding(): void
    {
        $sql = 'SELECT id, name, email FROM users WHERE id = 1';
        $result = $this->analyzer->analyze($sql, []);

        $this->assertPatternNotFound($result, 'select_star');
    }

    // ---------------------------------------------------------------
    // 3. Function on column detected
    // ---------------------------------------------------------------

    public function test_function_on_column_detected(): void
    {
        $sql = "SELECT id FROM users WHERE UPPER(name) = 'JOHN'";
        $result = $this->analyzer->analyze($sql, []);

        $this->assertPatternFound($result, 'function_on_column');
        $this->assertFindingExists($result, 'Function on column: UPPER(name)', Severity::Warning);

        // Check metadata on the finding
        $finding = $this->findFindingByTitle($result, 'Function on column: UPPER(name)');
        $this->assertNotNull($finding);
        $this->assertSame('UPPER', $finding->metadata['function']);
        $this->assertSame('name', $finding->metadata['column']);
    }

    // ---------------------------------------------------------------
    // 4. Multiple functions detected
    // ---------------------------------------------------------------

    public function test_multiple_functions_detected(): void
    {
        $sql = "SELECT id FROM users WHERE UPPER(name) = 'JOHN' AND YEAR(created_at) = 2024";
        $result = $this->analyzer->analyze($sql, []);

        $functionPatterns = array_filter(
            $result['anti_patterns'],
            fn (array $p) => $p['pattern'] === 'function_on_column'
        );

        $this->assertCount(2, $functionPatterns);

        $functionFindings = array_filter(
            $result['findings'],
            fn ($f) => str_starts_with($f->title, 'Function on column:')
        );

        $this->assertCount(2, $functionFindings);
    }

    // ---------------------------------------------------------------
    // 5. OR chain above threshold
    // ---------------------------------------------------------------

    public function test_or_chain_above_threshold(): void
    {
        $sql = 'SELECT id FROM users WHERE status = 1 OR status = 2 OR status = 3 OR status = 4';
        // This has 3 OR conditions — default threshold is 3
        $result = $this->analyzer->analyze($sql, []);

        $this->assertPatternFound($result, 'excessive_or_chains');

        $finding = $this->findFindingByPattern($result, 'Excessive OR chain:');
        $this->assertNotNull($finding);
        $this->assertSame(Severity::Warning, $finding->severity);
        $this->assertSame(3, $finding->metadata['or_count']);
    }

    // ---------------------------------------------------------------
    // 6. OR chain below threshold — no finding
    // ---------------------------------------------------------------

    public function test_or_chain_below_threshold_no_finding(): void
    {
        $sql = 'SELECT id FROM users WHERE status = 1 OR status = 2';
        // This has 1 OR condition — below default threshold of 3
        $result = $this->analyzer->analyze($sql, []);

        $this->assertPatternNotFound($result, 'excessive_or_chains');
    }

    // ---------------------------------------------------------------
    // 7. Correlated subquery detected
    // ---------------------------------------------------------------

    public function test_correlated_subquery_detected(): void
    {
        $sql = 'SELECT * FROM orders o WHERE o.amount > (SELECT AVG(amount) FROM orders o2 WHERE o2.customer_id = o.customer_id)';
        $result = $this->analyzer->analyze($sql, []);

        $this->assertPatternFound($result, 'correlated_subquery');
        $this->assertFindingExists($result, 'Correlated subquery detected', Severity::Warning);
    }

    // ---------------------------------------------------------------
    // 8. NOT IN with subquery detected
    // ---------------------------------------------------------------

    public function test_not_in_subquery_detected(): void
    {
        $sql = 'SELECT id FROM users WHERE id NOT IN (SELECT user_id FROM banned_users)';
        $result = $this->analyzer->analyze($sql, []);

        $this->assertPatternFound($result, 'not_in_subquery');
        $this->assertFindingExists($result, 'NOT IN with subquery', Severity::Warning);
    }

    // ---------------------------------------------------------------
    // 9. NOT IN without subquery — no finding
    // ---------------------------------------------------------------

    public function test_not_in_without_subquery_no_finding(): void
    {
        $sql = 'SELECT id FROM users WHERE status NOT IN (1, 2, 3)';
        $result = $this->analyzer->analyze($sql, []);

        $this->assertPatternNotFound($result, 'not_in_subquery');
    }

    // ---------------------------------------------------------------
    // 10. Leading wildcard detected
    // ---------------------------------------------------------------

    public function test_leading_wildcard_detected(): void
    {
        $sql = "SELECT id FROM users WHERE name LIKE '%john%'";
        $result = $this->analyzer->analyze($sql, []);

        $this->assertPatternFound($result, 'leading_wildcard');
        $this->assertFindingExists($result, 'Leading wildcard in LIKE', Severity::Warning);
    }

    // ---------------------------------------------------------------
    // 11. Trailing wildcard — no finding
    // ---------------------------------------------------------------

    public function test_trailing_wildcard_no_finding(): void
    {
        $sql = "SELECT id FROM users WHERE name LIKE 'john%'";
        $result = $this->analyzer->analyze($sql, []);

        $this->assertPatternNotFound($result, 'leading_wildcard');
    }

    // ---------------------------------------------------------------
    // 12. Missing LIMIT on large result
    // ---------------------------------------------------------------

    public function test_missing_limit_on_large_result(): void
    {
        $sql = 'SELECT id, name FROM users WHERE status = 1';
        $metrics = ['rows_examined' => 50000];
        $result = $this->analyzer->analyze($sql, $metrics);

        $this->assertPatternFound($result, 'missing_limit');

        $finding = $this->findFindingByTitle($result, 'Missing LIMIT on large result');
        $this->assertNotNull($finding);
        $this->assertSame(Severity::Optimization, $finding->severity);
        $this->assertSame(50000, $finding->metadata['rows_examined']);
    }

    // ---------------------------------------------------------------
    // 13. LIMIT present — no finding
    // ---------------------------------------------------------------

    public function test_limit_present_no_finding(): void
    {
        $sql = 'SELECT id, name FROM users WHERE status = 1 LIMIT 50';
        $metrics = ['rows_examined' => 50000];
        $result = $this->analyzer->analyze($sql, $metrics);

        $this->assertPatternNotFound($result, 'missing_limit');
    }

    // ---------------------------------------------------------------
    // 14. Aggregate without LIMIT — no finding
    // ---------------------------------------------------------------

    public function test_aggregate_no_limit_no_finding(): void
    {
        $sql = 'SELECT COUNT(*) FROM users WHERE status = 1';
        $metrics = ['rows_examined' => 50000];
        $result = $this->analyzer->analyze($sql, $metrics);

        $this->assertPatternNotFound($result, 'missing_limit');
    }

    // ---------------------------------------------------------------
    // 15. ORDER BY RAND() is critical
    // ---------------------------------------------------------------

    public function test_order_by_rand_is_critical(): void
    {
        $sql = 'SELECT * FROM users ORDER BY RAND() LIMIT 10';
        $result = $this->analyzer->analyze($sql, []);

        $this->assertPatternFound($result, 'order_by_rand');

        $finding = $this->findFindingByTitle($result, 'ORDER BY RAND() detected');
        $this->assertNotNull($finding);
        $this->assertSame(Severity::Critical, $finding->severity);
    }

    // ---------------------------------------------------------------
    // 16. Redundant DISTINCT with PRIMARY key
    // ---------------------------------------------------------------

    public function test_redundant_distinct_with_primary_key(): void
    {
        $sql = 'SELECT DISTINCT id, name FROM users WHERE id = 1';
        $metrics = ['indexes_used' => ['PRIMARY']];
        $result = $this->analyzer->analyze($sql, $metrics);

        $this->assertPatternFound($result, 'redundant_distinct');

        $finding = $this->findFindingByTitle($result, 'Potentially redundant DISTINCT');
        $this->assertNotNull($finding);
        $this->assertSame(Severity::Optimization, $finding->severity);
    }

    // ---------------------------------------------------------------
    // 17. Clean query — no patterns
    // ---------------------------------------------------------------

    public function test_clean_query_no_patterns(): void
    {
        $sql = 'SELECT id, name, email FROM users WHERE id = 1 LIMIT 1';
        $metrics = ['rows_examined' => 1];
        $result = $this->analyzer->analyze($sql, $metrics);

        $this->assertEmpty($result['anti_patterns'], 'Clean query should have no anti-patterns');
        $this->assertEmpty($result['findings'], 'Clean query should have no findings');
    }

    // ---------------------------------------------------------------
    // 18. Multiple patterns detected simultaneously
    // ---------------------------------------------------------------

    public function test_multiple_patterns_detected_simultaneously(): void
    {
        $sql = "SELECT * FROM users WHERE UPPER(name) LIKE '%john%' ORDER BY RAND() ";
        $result = $this->analyzer->analyze($sql, []);

        $patternNames = array_column($result['anti_patterns'], 'pattern');

        $this->assertContains('select_star', $patternNames);
        $this->assertContains('function_on_column', $patternNames);
        $this->assertContains('leading_wildcard', $patternNames);
        $this->assertContains('order_by_rand', $patternNames);

        // At least 4 patterns should be found
        $this->assertGreaterThanOrEqual(4, count($result['anti_patterns']));
        $this->assertGreaterThanOrEqual(4, count($result['findings']));
    }

    // ---------------------------------------------------------------
    // 19. Custom thresholds respected
    // ---------------------------------------------------------------

    public function test_custom_thresholds_respected(): void
    {
        // Custom analyzer: OR threshold 5, missing limit threshold 100
        $customAnalyzer = new AntiPatternAnalyzer(orChainThreshold: 5, missingLimitRowThreshold: 100);

        // 3 OR conditions — would trigger default (3) but not custom (5)
        $sql = 'SELECT id FROM users WHERE a = 1 OR b = 2 OR c = 3 OR d = 4';
        $result = $customAnalyzer->analyze($sql, []);
        $this->assertPatternNotFound($result, 'excessive_or_chains');

        // 5 OR conditions — triggers custom threshold
        $sql2 = 'SELECT id FROM users WHERE a = 1 OR b = 2 OR c = 3 OR d = 4 OR e = 5 OR f = 6';
        $result2 = $customAnalyzer->analyze($sql2, []);
        $this->assertPatternFound($result2, 'excessive_or_chains');

        // 500 rows examined — would trigger default (10000) but custom is 100, so it triggers
        $sql3 = 'SELECT id, name FROM users WHERE active = 1';
        $result3 = $customAnalyzer->analyze($sql3, ['rows_examined' => 500]);
        $this->assertPatternFound($result3, 'missing_limit');

        // 50 rows examined — below even the custom threshold of 100
        $result4 = $customAnalyzer->analyze($sql3, ['rows_examined' => 50]);
        $this->assertPatternNotFound($result4, 'missing_limit');
    }

    // ---------------------------------------------------------------
    // 20. Finding categories are anti_pattern
    // ---------------------------------------------------------------

    public function test_finding_categories_are_anti_pattern(): void
    {
        $sql = "SELECT * FROM users WHERE UPPER(name) = 'JOHN' ORDER BY RAND()";
        $result = $this->analyzer->analyze($sql, []);

        $this->assertNotEmpty($result['findings'], 'Should have findings');

        foreach ($result['findings'] as $finding) {
            $this->assertSame(
                'anti_pattern',
                $finding->category,
                sprintf('Finding "%s" should have category "anti_pattern"', $finding->title)
            );
        }
    }

    // ---------------------------------------------------------------
    // Helper assertion methods
    // ---------------------------------------------------------------

    /**
     * @param  array{anti_patterns: array<int, array<string, string>>, findings: mixed[]}  $result
     */
    private function assertPatternFound(array $result, string $patternName): void
    {
        $patternNames = array_column($result['anti_patterns'], 'pattern');
        $this->assertContains($patternName, $patternNames, "Expected anti-pattern '{$patternName}' to be found");
    }

    /**
     * @param  array{anti_patterns: array<int, array<string, string>>, findings: mixed[]}  $result
     */
    private function assertPatternNotFound(array $result, string $patternName): void
    {
        $patternNames = array_column($result['anti_patterns'], 'pattern');
        $this->assertNotContains($patternName, $patternNames, "Expected anti-pattern '{$patternName}' NOT to be found");
    }

    /**
     * @param  array{anti_patterns: mixed[], findings: \QuerySentinel\Support\Finding[]}  $result
     */
    private function assertFindingExists(array $result, string $title, Severity $severity): void
    {
        $finding = $this->findFindingByTitle($result, $title);
        $this->assertNotNull($finding, "Expected finding with title '{$title}' to exist");
        $this->assertSame($severity, $finding->severity, "Expected finding '{$title}' to have severity '{$severity->value}'");
    }

    /**
     * @param  array{anti_patterns: mixed[], findings: \QuerySentinel\Support\Finding[]}  $result
     */
    private function findFindingByTitle(array $result, string $title): ?\QuerySentinel\Support\Finding
    {
        foreach ($result['findings'] as $finding) {
            if ($finding->title === $title) {
                return $finding;
            }
        }

        return null;
    }

    /**
     * @param  array{anti_patterns: mixed[], findings: \QuerySentinel\Support\Finding[]}  $result
     */
    private function findFindingByPattern(array $result, string $titlePrefix): ?\QuerySentinel\Support\Finding
    {
        foreach ($result['findings'] as $finding) {
            if (str_starts_with($finding->title, $titlePrefix)) {
                return $finding;
            }
        }

        return null;
    }
}
