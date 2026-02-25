<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QuerySentinel\Analyzers\IndexSynthesisAnalyzer;
use QuerySentinel\Enums\Severity;
use QuerySentinel\Support\Finding;

final class IndexSynthesisAnalyzerTest extends TestCase
{
    private IndexSynthesisAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new IndexSynthesisAnalyzer(maxRecommendations: 3, maxColumnsPerIndex: 5);
    }

    // ---------------------------------------------------------------
    // 1. Simple WHERE equality -> single column index recommendation
    // ---------------------------------------------------------------

    public function test_simple_where_equality_recommends_single_column_index(): void
    {
        $sql = 'SELECT id FROM users WHERE status = 1';
        $result = $this->analyzer->analyze($sql, [], null, null);

        $recommendations = $result['index_synthesis']['recommendations'];
        $this->assertNotEmpty($recommendations);

        $rec = $recommendations[0];
        $this->assertSame('users', $rec['table']);
        $this->assertContains('status', $rec['columns']);
    }

    // ---------------------------------------------------------------
    // 2. WHERE equality + range -> composite ERS index
    // ---------------------------------------------------------------

    public function test_where_equality_plus_range_creates_ers_index(): void
    {
        $sql = 'SELECT id FROM users WHERE status = 1 AND created_at > "2024-01-01"';
        $result = $this->analyzer->analyze($sql, [], null, null);

        $recommendations = $result['index_synthesis']['recommendations'];
        $this->assertNotEmpty($recommendations);

        $rec = $recommendations[0];
        $this->assertSame('users', $rec['table']);

        // Equality column (status) must come before range column (created_at) per ERS
        $statusIdx = array_search('status', $rec['columns'], true);
        $createdAtIdx = array_search('created_at', $rec['columns'], true);

        $this->assertNotFalse($statusIdx);
        $this->assertNotFalse($createdAtIdx);
        $this->assertLessThan($createdAtIdx, $statusIdx, 'Equality column must precede range column');
    }

    // ---------------------------------------------------------------
    // 3. WHERE + ORDER BY -> columns ordered correctly (equality, range, sort)
    // ---------------------------------------------------------------

    public function test_where_plus_order_by_produces_ers_order(): void
    {
        $sql = 'SELECT id FROM orders WHERE status = "active" AND amount > 100 ORDER BY created_at DESC';
        $result = $this->analyzer->analyze($sql, [], null, null);

        $recommendations = $result['index_synthesis']['recommendations'];
        $this->assertNotEmpty($recommendations);

        $rec = $recommendations[0];
        $columns = $rec['columns'];

        // Expected ERS order: status (equality), amount (range), created_at (sort)
        $statusIdx = array_search('status', $columns, true);
        $amountIdx = array_search('amount', $columns, true);
        $createdAtIdx = array_search('created_at', $columns, true);

        $this->assertNotFalse($statusIdx);
        $this->assertNotFalse($amountIdx);
        $this->assertNotFalse($createdAtIdx);

        $this->assertLessThan($amountIdx, $statusIdx, 'Equality before range');
        $this->assertLessThan($createdAtIdx, $amountIdx, 'Range before sort');
    }

    // ---------------------------------------------------------------
    // 4. WHERE + JOIN -> join columns included
    // ---------------------------------------------------------------

    public function test_where_plus_join_includes_join_columns(): void
    {
        $sql = 'SELECT orders.id FROM orders JOIN users ON users.id = orders.user_id WHERE orders.status = "active"';
        $result = $this->analyzer->analyze($sql, [], null, null);

        $recommendations = $result['index_synthesis']['recommendations'];
        $this->assertNotEmpty($recommendations);

        // There should be recommendations for orders table
        $orderRec = $this->findRecommendationForTable($recommendations, 'orders');
        $this->assertNotNull($orderRec, 'Should have recommendation for orders table');

        // The orders recommendation should include status (equality) and user_id (join)
        $this->assertContains('status', $orderRec['columns']);
        $this->assertContains('user_id', $orderRec['columns']);

        // Equality column should come before join column
        $statusIdx = array_search('status', $orderRec['columns'], true);
        $userIdIdx = array_search('user_id', $orderRec['columns'], true);
        $this->assertLessThan($userIdIdx, $statusIdx);
    }

    // ---------------------------------------------------------------
    // 5. Covering index detection when SELECT lists specific columns
    // ---------------------------------------------------------------

    public function test_covering_index_when_select_lists_specific_columns(): void
    {
        $sql = 'SELECT name, email FROM users WHERE status = 1';
        $result = $this->analyzer->analyze($sql, [], null, null);

        $recommendations = $result['index_synthesis']['recommendations'];
        $this->assertNotEmpty($recommendations);

        $rec = $recommendations[0];
        $this->assertSame('covering', $rec['type']);
        $this->assertContains('status', $rec['columns']);
        // SELECT columns should also be in the index for covering
        $this->assertContains('name', $rec['columns']);
        $this->assertContains('email', $rec['columns']);
    }

    // ---------------------------------------------------------------
    // 6. Column count capping at maxColumnsPerIndex
    // ---------------------------------------------------------------

    public function test_column_count_capped_at_max_columns_per_index(): void
    {
        // Construct a query with many columns
        $sql = 'SELECT a, b, c, d FROM users WHERE status = 1 AND type = 2 AND role = 3 AND active = 1 AND verified = 1 AND level > 5 ORDER BY created_at';
        $result = $this->analyzer->analyze($sql, [], null, null);

        $recommendations = $result['index_synthesis']['recommendations'];
        $this->assertNotEmpty($recommendations);

        foreach ($recommendations as $rec) {
            $this->assertLessThanOrEqual(5, count($rec['columns']),
                'Column count should not exceed maxColumnsPerIndex (5)');
        }
    }

    // ---------------------------------------------------------------
    // 7. Max recommendations limit
    // ---------------------------------------------------------------

    public function test_max_recommendations_limit(): void
    {
        // Build a query that touches many tables via joins
        $sql = 'SELECT a.id FROM table_a a '
            .'JOIN table_b b ON b.a_id = a.id '
            .'JOIN table_c c ON c.b_id = b.id '
            .'JOIN table_d d ON d.c_id = c.id '
            .'JOIN table_e e ON e.d_id = d.id '
            .'WHERE a.status = 1 AND b.type = 2 AND c.active = 1 AND d.verified = 1';

        $result = $this->analyzer->analyze($sql, [], null, null);

        $recommendations = $result['index_synthesis']['recommendations'];
        $this->assertLessThanOrEqual(3, count($recommendations), 'Recommendations should not exceed maxRecommendations (3)');
    }

    // ---------------------------------------------------------------
    // 8. Overlap detection with existing indexes
    // ---------------------------------------------------------------

    public function test_overlap_detection_with_existing_indexes(): void
    {
        $sql = 'SELECT id FROM users WHERE status = 1 AND role = "admin"';

        $indexAnalysis = [
            'users' => [
                'idx_status' => [
                    'columns' => [
                        1 => ['column' => 'status'],
                    ],
                    'is_used' => true,
                    'is_unique' => false,
                    'table_rows' => 10000,
                ],
            ],
        ];

        $result = $this->analyzer->analyze($sql, [], $indexAnalysis, null);

        $recommendations = $result['index_synthesis']['recommendations'];
        $this->assertNotEmpty($recommendations);

        $rec = $recommendations[0];
        // Should detect overlap with idx_status since 'status' is the leading column
        $this->assertContains('idx_status', $rec['overlaps_with']);
    }

    // ---------------------------------------------------------------
    // 9. Existing index assessment: optimal
    // ---------------------------------------------------------------

    public function test_existing_index_assessment_optimal(): void
    {
        $sql = 'SELECT id FROM users WHERE status = 1';

        $indexAnalysis = [
            'users' => [
                'idx_status' => [
                    'columns' => [1 => ['column' => 'status']],
                    'is_used' => true,
                    'is_unique' => false,
                    'table_rows' => 10000,
                ],
            ],
        ];

        $result = $this->analyzer->analyze($sql, [], $indexAnalysis, null);

        $assessment = $result['index_synthesis']['existing_index_assessment'];
        $statusAssessment = $this->findAssessmentForIndex($assessment, 'idx_status');

        $this->assertNotNull($statusAssessment);
        $this->assertSame('optimal', $statusAssessment['status']);
    }

    // ---------------------------------------------------------------
    // 10. Existing index assessment: suboptimal
    // ---------------------------------------------------------------

    public function test_existing_index_assessment_suboptimal(): void
    {
        // Query filters on 'role', but index leading column is 'name'
        $sql = 'SELECT id FROM users WHERE role = "admin"';

        $indexAnalysis = [
            'users' => [
                'idx_name_role' => [
                    'columns' => [
                        1 => ['column' => 'name'],
                        2 => ['column' => 'role'],
                    ],
                    'is_used' => true,
                    'is_unique' => false,
                    'table_rows' => 10000,
                ],
            ],
        ];

        $result = $this->analyzer->analyze($sql, [], $indexAnalysis, null);

        $assessment = $result['index_synthesis']['existing_index_assessment'];
        $indexAssessment = $this->findAssessmentForIndex($assessment, 'idx_name_role');

        $this->assertNotNull($indexAssessment);
        $this->assertSame('suboptimal', $indexAssessment['status']);
        $this->assertStringContainsString('name', $indexAssessment['reason']);
    }

    // ---------------------------------------------------------------
    // 11. Existing index assessment: redundant
    // ---------------------------------------------------------------

    public function test_existing_index_assessment_redundant(): void
    {
        $sql = 'SELECT id FROM users WHERE status = 1';

        $indexAnalysis = [
            'users' => [
                'idx_status' => [
                    'columns' => [1 => ['column' => 'status']],
                    'is_used' => true,
                    'is_unique' => false,
                    'table_rows' => 10000,
                ],
                'idx_status_role' => [
                    'columns' => [
                        1 => ['column' => 'status'],
                        2 => ['column' => 'role'],
                    ],
                    'is_used' => false,
                    'is_unique' => false,
                    'table_rows' => 10000,
                ],
            ],
        ];

        $result = $this->analyzer->analyze($sql, [], $indexAnalysis, null);

        $assessment = $result['index_synthesis']['existing_index_assessment'];
        $statusAssessment = $this->findAssessmentForIndex($assessment, 'idx_status');

        $this->assertNotNull($statusAssessment);
        $this->assertSame('redundant', $statusAssessment['status']);
        $this->assertStringContainsString('prefix', $statusAssessment['reason']);
    }

    // ---------------------------------------------------------------
    // 12. Existing index assessment: unused
    // ---------------------------------------------------------------

    public function test_existing_index_assessment_unused(): void
    {
        $sql = 'SELECT id FROM users WHERE status = 1';

        $indexAnalysis = [
            'users' => [
                'idx_email' => [
                    'columns' => [1 => ['column' => 'email']],
                    'is_used' => false,
                    'is_unique' => false,
                    'table_rows' => 10000,
                ],
            ],
        ];

        $result = $this->analyzer->analyze($sql, [], $indexAnalysis, null);

        $assessment = $result['index_synthesis']['existing_index_assessment'];
        $emailAssessment = $this->findAssessmentForIndex($assessment, 'idx_email');

        $this->assertNotNull($emailAssessment);
        $this->assertSame('unused', $emailAssessment['status']);
    }

    // ---------------------------------------------------------------
    // 13. Empty WHERE -> no recommendations
    // ---------------------------------------------------------------

    public function test_empty_where_no_recommendations(): void
    {
        $sql = 'SELECT * FROM users';
        $result = $this->analyzer->analyze($sql, [], null, null);

        $recommendations = $result['index_synthesis']['recommendations'];
        $this->assertEmpty($recommendations, 'No WHERE clause should produce no index recommendations');
        $this->assertEmpty($result['findings']);
    }

    // ---------------------------------------------------------------
    // 14. SELECT * -> no covering index extension
    // ---------------------------------------------------------------

    public function test_select_star_no_covering_index(): void
    {
        $sql = 'SELECT * FROM users WHERE status = 1';
        $result = $this->analyzer->analyze($sql, [], null, null);

        $recommendations = $result['index_synthesis']['recommendations'];
        $this->assertNotEmpty($recommendations);

        $rec = $recommendations[0];
        // Should NOT be a covering index type when SELECT *
        $this->assertNotSame('covering', $rec['type']);
    }

    // ---------------------------------------------------------------
    // 15. Cardinality drift data influences improvement estimate
    // ---------------------------------------------------------------

    public function test_cardinality_drift_influences_improvement_estimate(): void
    {
        $sql = 'SELECT id FROM users WHERE status = 1';

        $cardinalityDrift = [
            'per_table' => [
                'users' => [
                    'drift_ratio' => 0.8,
                    'drift_direction' => 'under',
                    'severity' => 'warning',
                ],
            ],
        ];

        $result = $this->analyzer->analyze($sql, [], null, $cardinalityDrift);

        $recommendations = $result['index_synthesis']['recommendations'];
        $this->assertNotEmpty($recommendations);

        // With high drift and no existing index, should be 'high' improvement
        $this->assertSame('high', $recommendations[0]['estimated_improvement']);
    }

    // ---------------------------------------------------------------
    // 16. DDL generation format validation
    // ---------------------------------------------------------------

    public function test_ddl_generation_format(): void
    {
        $sql = 'SELECT id FROM orders WHERE status = 1 AND customer_id = 5';
        $result = $this->analyzer->analyze($sql, [], null, null);

        $recommendations = $result['index_synthesis']['recommendations'];
        $this->assertNotEmpty($recommendations);

        $rec = $recommendations[0];
        $ddl = $rec['ddl'];

        $this->assertStringStartsWith('CREATE INDEX', $ddl);
        $this->assertStringContainsString('ON `orders`', $ddl);
        $this->assertStringEndsWith(';', $ddl);

        // Should contain backtick-quoted column names
        foreach ($rec['columns'] as $col) {
            $this->assertStringContainsString("`{$col}`", $ddl);
        }
    }

    // ---------------------------------------------------------------
    // 17. Multiple tables -> per-table recommendations
    // ---------------------------------------------------------------

    public function test_multiple_tables_per_table_recommendations(): void
    {
        $sql = 'SELECT orders.id FROM orders JOIN users ON users.id = orders.user_id WHERE orders.status = "active" AND users.role = "admin"';
        $result = $this->analyzer->analyze($sql, [], null, null);

        $recommendations = $result['index_synthesis']['recommendations'];

        // Extract unique tables from recommendations
        $tables = array_unique(array_column($recommendations, 'table'));

        $this->assertGreaterThanOrEqual(2, count($tables), 'Should have recommendations for multiple tables');
        $this->assertContains('orders', $tables);
        $this->assertContains('users', $tables);
    }

    // ---------------------------------------------------------------
    // 18. Custom thresholds (maxRecommendations, maxColumnsPerIndex)
    // ---------------------------------------------------------------

    public function test_custom_thresholds_max_recommendations(): void
    {
        $customAnalyzer = new IndexSynthesisAnalyzer(maxRecommendations: 1, maxColumnsPerIndex: 3);

        $sql = 'SELECT a.id FROM table_a a '
            .'JOIN table_b b ON b.a_id = a.id '
            .'JOIN table_c c ON c.b_id = b.id '
            .'WHERE a.status = 1 AND b.type = 2 AND c.active = 1';

        $result = $customAnalyzer->analyze($sql, [], null, null);

        $recommendations = $result['index_synthesis']['recommendations'];
        $this->assertLessThanOrEqual(1, count($recommendations), 'Should respect maxRecommendations=1');
    }

    // ---------------------------------------------------------------
    // 19. Custom thresholds: maxColumnsPerIndex
    // ---------------------------------------------------------------

    public function test_custom_thresholds_max_columns_per_index(): void
    {
        $customAnalyzer = new IndexSynthesisAnalyzer(maxRecommendations: 10, maxColumnsPerIndex: 2);

        $sql = 'SELECT id FROM users WHERE status = 1 AND role = "admin" AND active = 1 AND verified = 1';
        $result = $customAnalyzer->analyze($sql, [], null, null);

        $recommendations = $result['index_synthesis']['recommendations'];
        $this->assertNotEmpty($recommendations);

        foreach ($recommendations as $rec) {
            $this->assertLessThanOrEqual(2, count($rec['columns']),
                'Column count should not exceed maxColumnsPerIndex=2');
        }
    }

    // ---------------------------------------------------------------
    // 20. High improvement when rows_examined > 10000 and no existing index
    // ---------------------------------------------------------------

    public function test_high_improvement_when_rows_examined_above_threshold(): void
    {
        $sql = 'SELECT id FROM users WHERE status = 1';
        $metrics = ['rows_examined' => 50000];

        $result = $this->analyzer->analyze($sql, $metrics, null, null);

        $recommendations = $result['index_synthesis']['recommendations'];
        $this->assertNotEmpty($recommendations);
        $this->assertSame('high', $recommendations[0]['estimated_improvement']);
    }

    // ---------------------------------------------------------------
    // 21. Findings generated for recommendations
    // ---------------------------------------------------------------

    public function test_findings_generated_for_recommendations(): void
    {
        $sql = 'SELECT id FROM users WHERE status = 1';
        $metrics = ['rows_examined' => 50000];

        $result = $this->analyzer->analyze($sql, $metrics, null, null);

        $this->assertNotEmpty($result['findings']);

        $finding = $result['findings'][0];
        $this->assertInstanceOf(Finding::class, $finding);
        $this->assertSame('index_synthesis', $finding->category);
        $this->assertStringContainsString('users', $finding->title);
        $this->assertNotNull($finding->recommendation);
        $this->assertStringContainsString('CREATE INDEX', $finding->recommendation);
    }

    // ---------------------------------------------------------------
    // 22. Finding severity matches estimated improvement
    // ---------------------------------------------------------------

    public function test_finding_severity_matches_estimated_improvement(): void
    {
        // High improvement -> Warning severity
        $sql = 'SELECT id FROM users WHERE status = 1';
        $metrics = ['rows_examined' => 50000];

        $result = $this->analyzer->analyze($sql, $metrics, null, null);

        $this->assertNotEmpty($result['findings']);

        $highFinding = $result['findings'][0];
        $this->assertSame(Severity::Warning, $highFinding->severity);
    }

    // ---------------------------------------------------------------
    // 23. BETWEEN operator classified as range
    // ---------------------------------------------------------------

    public function test_between_operator_classified_as_range(): void
    {
        $sql = 'SELECT id FROM orders WHERE status = "active" AND amount BETWEEN 100 AND 500';
        $result = $this->analyzer->analyze($sql, [], null, null);

        $recommendations = $result['index_synthesis']['recommendations'];
        $this->assertNotEmpty($recommendations);

        $rec = $recommendations[0];
        // status (equality) should come before amount (range/BETWEEN)
        $statusIdx = array_search('status', $rec['columns'], true);
        $amountIdx = array_search('amount', $rec['columns'], true);

        $this->assertNotFalse($statusIdx);
        $this->assertNotFalse($amountIdx);
        $this->assertLessThan($amountIdx, $statusIdx);
    }

    // ---------------------------------------------------------------
    // 24. IN operator classified as range
    // ---------------------------------------------------------------

    public function test_in_operator_classified_as_range(): void
    {
        $sql = 'SELECT id FROM orders WHERE customer_id = 5 AND status IN ("active", "pending")';
        $result = $this->analyzer->analyze($sql, [], null, null);

        $recommendations = $result['index_synthesis']['recommendations'];
        $this->assertNotEmpty($recommendations);

        $rec = $recommendations[0];
        // customer_id (equality) should come before status (IN = range)
        $customerIdx = array_search('customer_id', $rec['columns'], true);
        $statusIdx = array_search('status', $rec['columns'], true);

        $this->assertNotFalse($customerIdx);
        $this->assertNotFalse($statusIdx);
        $this->assertLessThan($statusIdx, $customerIdx);
    }

    // ---------------------------------------------------------------
    // 25. Index type is composite for equality + range
    // ---------------------------------------------------------------

    public function test_index_type_composite_for_equality_and_range(): void
    {
        $sql = 'SELECT * FROM users WHERE status = 1 AND created_at > "2024-01-01"';
        $result = $this->analyzer->analyze($sql, [], null, null);

        $recommendations = $result['index_synthesis']['recommendations'];
        $this->assertNotEmpty($recommendations);

        $rec = $recommendations[0];
        $this->assertSame('composite', $rec['type']);
    }

    // ---------------------------------------------------------------
    // 26. Finding metadata contains table, columns, type, and improvement
    // ---------------------------------------------------------------

    public function test_finding_metadata_contains_expected_keys(): void
    {
        $sql = 'SELECT id FROM users WHERE status = 1';
        $result = $this->analyzer->analyze($sql, ['rows_examined' => 50000], null, null);

        $this->assertNotEmpty($result['findings']);

        $finding = $result['findings'][0];
        $this->assertArrayHasKey('table', $finding->metadata);
        $this->assertArrayHasKey('columns', $finding->metadata);
        $this->assertArrayHasKey('type', $finding->metadata);
        $this->assertArrayHasKey('estimated_improvement', $finding->metadata);
        $this->assertArrayHasKey('overlaps_with', $finding->metadata);
    }

    // ---------------------------------------------------------------
    // 27. Null indexAnalysis produces no existing index assessment
    // ---------------------------------------------------------------

    public function test_null_index_analysis_produces_empty_assessment(): void
    {
        $sql = 'SELECT id FROM users WHERE status = 1';
        $result = $this->analyzer->analyze($sql, [], null, null);

        $assessment = $result['index_synthesis']['existing_index_assessment'];
        $this->assertEmpty($assessment);
    }

    // ---------------------------------------------------------------
    // 28. Recommendation rationale includes ERS components
    // ---------------------------------------------------------------

    public function test_recommendation_rationale_includes_ers_components(): void
    {
        $sql = 'SELECT id FROM orders WHERE status = "active" AND amount > 100 ORDER BY created_at DESC';
        $result = $this->analyzer->analyze($sql, [], null, null);

        $recommendations = $result['index_synthesis']['recommendations'];
        $this->assertNotEmpty($recommendations);

        $rec = $recommendations[0];
        $this->assertStringContainsString('equality', $rec['rationale']);
        $this->assertStringContainsString('range', $rec['rationale']);
        $this->assertStringContainsString('sort', $rec['rationale']);
    }

    // ---------------------------------------------------------------
    // Helper methods
    // ---------------------------------------------------------------

    /**
     * @param  array<int, array<string, mixed>>  $recommendations
     * @return array<string, mixed>|null
     */
    private function findRecommendationForTable(array $recommendations, string $table): ?array
    {
        foreach ($recommendations as $rec) {
            if ($rec['table'] === $table) {
                return $rec;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array{index: string, status: string, reason: string}>  $assessment
     * @return array{index: string, status: string, reason: string}|null
     */
    private function findAssessmentForIndex(array $assessment, string $indexName): ?array
    {
        foreach ($assessment as $item) {
            if ($item['index'] === $indexName) {
                return $item;
            }
        }

        return null;
    }
}
