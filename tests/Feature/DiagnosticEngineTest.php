<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Feature;

use QuerySentinel\Analyzers\ExecutionProfileAnalyzer;
use QuerySentinel\Analyzers\JoinAnalyzer;
use QuerySentinel\Analyzers\PlanStabilityAnalyzer;
use QuerySentinel\Analyzers\RegressionSafetyAnalyzer;
use QuerySentinel\Enums\ComplexityClass;
use QuerySentinel\Enums\Severity;
use QuerySentinel\Support\DiagnosticReport;
use QuerySentinel\Support\EnvironmentContext;
use QuerySentinel\Support\ExecutionProfile;
use QuerySentinel\Support\Finding;
use QuerySentinel\Support\Report;
use QuerySentinel\Support\Result;
use QuerySentinel\Support\SqlParser;
use QuerySentinel\Tests\TestCase;

final class DiagnosticEngineTest extends TestCase
{
    // ---------------------------------------------------------------
    // Finding DTO
    // ---------------------------------------------------------------

    public function test_finding_dto_serialization(): void
    {
        $finding = new Finding(
            severity: Severity::Warning,
            category: 'test_cat',
            title: 'Test Title',
            description: 'Test Description',
            recommendation: 'Fix it',
            metadata: ['key' => 'value'],
        );

        $array = $finding->toArray();

        $this->assertSame('warning', $array['severity']);
        $this->assertSame('test_cat', $array['category']);
        $this->assertSame('Test Title', $array['title']);
        $this->assertSame('Test Description', $array['description']);
        $this->assertSame('Fix it', $array['recommendation']);
        $this->assertSame(['key' => 'value'], $array['metadata']);
    }

    public function test_finding_from_legacy(): void
    {
        $legacy = [
            'severity' => 'critical',
            'title' => 'Full table scan',
            'description' => 'Table scan detected',
            'recommendation' => 'Add index',
            'category' => 'rule',
        ];

        $finding = Finding::fromLegacy($legacy);

        $this->assertSame(Severity::Critical, $finding->severity);
        $this->assertSame('rule', $finding->category);
        $this->assertSame('Full table scan', $finding->title);
    }

    // ---------------------------------------------------------------
    // Severity Enum
    // ---------------------------------------------------------------

    public function test_severity_console_color(): void
    {
        $this->assertSame('red', Severity::Critical->consoleColor());
        $this->assertSame('yellow', Severity::Warning->consoleColor());
        $this->assertSame('green', Severity::Optimization->consoleColor());
        $this->assertSame('gray', Severity::Info->consoleColor());
    }

    public function test_severity_icon(): void
    {
        $this->assertSame('!!', Severity::Critical->icon());
        $this->assertSame('!', Severity::Warning->icon());
        $this->assertSame('*', Severity::Optimization->icon());
        $this->assertSame('i', Severity::Info->icon());
    }

    public function test_severity_priority(): void
    {
        $this->assertSame(1, Severity::Critical->priority());
        $this->assertSame(2, Severity::Warning->priority());
        $this->assertSame(3, Severity::Optimization->priority());
        $this->assertSame(4, Severity::Info->priority());
    }

    // ---------------------------------------------------------------
    // ExecutionProfile DTO
    // ---------------------------------------------------------------

    public function test_execution_profile_to_array(): void
    {
        $profile = new ExecutionProfile(
            nestedLoopDepth: 2,
            joinFanouts: ['users' => 100.0],
            btreeDepths: ['PRIMARY' => 3],
            logicalReads: 5000,
            physicalReads: 50,
            scanComplexity: ComplexityClass::Logarithmic,
            sortComplexity: ComplexityClass::Constant,
        );

        $array = $profile->toArray();

        $this->assertSame(2, $array['nested_loop_depth']);
        $this->assertSame(['users' => 100.0], $array['join_fanouts']);
        $this->assertSame(5000, $array['logical_reads']);
        $this->assertSame('O(log n)', $array['scan_complexity']);
        $this->assertSame('O(1)', $array['sort_complexity']);
    }

    // ---------------------------------------------------------------
    // EnvironmentContext DTO
    // ---------------------------------------------------------------

    public function test_environment_context_to_array(): void
    {
        $env = new EnvironmentContext(
            mysqlVersion: '8.0.36',
            bufferPoolSizeBytes: 134217728,
            innodbIoCapacity: 200,
            innodbPageSize: 16384,
            tmpTableSize: 16777216,
            maxHeapTableSize: 16777216,
            bufferPoolUtilization: 0.75,
            isColdCache: false,
            databaseName: 'test_db',
        );

        $array = $env->toArray();

        $this->assertSame('8.0.36', $array['mysql_version']);
        $this->assertSame(128.0, $array['buffer_pool_size_mb']);
        $this->assertFalse($array['is_cold_cache']);
        $this->assertSame(0.75, $array['buffer_pool_utilization']);
    }

    public function test_environment_context_cold_cache_detection(): void
    {
        $env = new EnvironmentContext(
            mysqlVersion: '8.0.36',
            bufferPoolSizeBytes: 134217728,
            innodbIoCapacity: 200,
            innodbPageSize: 16384,
            tmpTableSize: 16777216,
            maxHeapTableSize: 16777216,
            bufferPoolUtilization: 0.3,
            isColdCache: true,
            databaseName: 'test_db',
        );

        $this->assertTrue($env->isColdCache);
    }

    // ---------------------------------------------------------------
    // DiagnosticReport
    // ---------------------------------------------------------------

    public function test_diagnostic_report_findings_by_category(): void
    {
        $report = $this->createDiagnosticReport([
            new Finding(Severity::Warning, 'rule', 'Rule 1', 'Desc'),
            new Finding(Severity::Info, 'explain_why', 'Why 1', 'Desc'),
            new Finding(Severity::Warning, 'rule', 'Rule 2', 'Desc'),
            new Finding(Severity::Critical, 'join_analysis', 'Join 1', 'Desc'),
        ]);

        $ruleFindings = $report->findingsByCategory('rule');
        $this->assertCount(2, $ruleFindings);
        $this->assertSame('Rule 1', $ruleFindings[0]->title);

        $joinFindings = $report->findingsByCategory('join_analysis');
        $this->assertCount(1, $joinFindings);
    }

    public function test_diagnostic_report_finding_counts(): void
    {
        $report = $this->createDiagnosticReport([
            new Finding(Severity::Critical, 'a', 'T', 'D'),
            new Finding(Severity::Warning, 'b', 'T', 'D'),
            new Finding(Severity::Warning, 'c', 'T', 'D'),
            new Finding(Severity::Optimization, 'd', 'T', 'D'),
            new Finding(Severity::Info, 'e', 'T', 'D'),
            new Finding(Severity::Info, 'f', 'T', 'D'),
            new Finding(Severity::Info, 'g', 'T', 'D'),
        ]);

        $counts = $report->findingCounts();

        $this->assertSame(1, $counts['critical']);
        $this->assertSame(2, $counts['warning']);
        $this->assertSame(1, $counts['optimization']);
        $this->assertSame(3, $counts['info']);
    }

    public function test_diagnostic_report_worst_severity(): void
    {
        $report = $this->createDiagnosticReport([
            new Finding(Severity::Info, 'a', 'T', 'D'),
            new Finding(Severity::Warning, 'b', 'T', 'D'),
        ]);

        $this->assertSame(Severity::Warning, $report->worstSeverity());
    }

    public function test_diagnostic_report_worst_severity_with_no_findings(): void
    {
        $report = $this->createDiagnosticReport([]);

        $this->assertSame(Severity::Info, $report->worstSeverity());
    }

    public function test_diagnostic_report_to_array_includes_deep_sections(): void
    {
        $env = new EnvironmentContext(
            mysqlVersion: '8.0.36',
            bufferPoolSizeBytes: 134217728,
            innodbIoCapacity: 200,
            innodbPageSize: 16384,
            tmpTableSize: 16777216,
            maxHeapTableSize: 16777216,
            bufferPoolUtilization: 0.75,
            isColdCache: false,
            databaseName: 'test_db',
        );

        $profile = new ExecutionProfile(
            nestedLoopDepth: 1,
            joinFanouts: [],
            btreeDepths: [],
            logicalReads: 100,
            physicalReads: 5,
            scanComplexity: ComplexityClass::Logarithmic,
            sortComplexity: ComplexityClass::Constant,
        );

        $report = new DiagnosticReport(
            report: $this->createBaseReport(),
            findings: [new Finding(Severity::Info, 'test', 'T', 'D')],
            environment: $env,
            executionProfile: $profile,
            indexAnalysis: ['users' => []],
            joinAnalysis: ['join_types' => ['simple']],
            stabilityAnalysis: ['optimizer_hints' => []],
            safetyAnalysis: ['has_charset_conversion' => false],
        );

        $array = $report->toArray();

        $this->assertArrayHasKey('diagnostic', $array);
        $this->assertArrayHasKey('environment', $array['diagnostic']);
        $this->assertArrayHasKey('execution_profile', $array['diagnostic']);
        $this->assertArrayHasKey('index_analysis', $array['diagnostic']);
        $this->assertArrayHasKey('join_analysis', $array['diagnostic']);
        $this->assertArrayHasKey('plan_stability', $array['diagnostic']);
        $this->assertArrayHasKey('regression_safety', $array['diagnostic']);
        $this->assertArrayHasKey('findings', $array['diagnostic']);
        $this->assertArrayHasKey('finding_counts', $array['diagnostic']);
        $this->assertArrayHasKey('worst_severity', $array['diagnostic']);
    }

    public function test_diagnostic_report_to_json(): void
    {
        $report = $this->createDiagnosticReport([
            new Finding(Severity::Info, 'test', 'Title', 'Description'),
        ]);

        $json = $report->toJson(JSON_PRETTY_PRINT);

        $this->assertJson($json);
        $decoded = json_decode($json, true);
        $this->assertArrayHasKey('diagnostic', $decoded);
    }

    // ---------------------------------------------------------------
    // SqlParser
    // ---------------------------------------------------------------

    public function test_sql_parser_extracts_where_columns(): void
    {
        $sql = "SELECT * FROM users WHERE users.id = 1 AND status = 'active' ORDER BY created_at";
        $columns = SqlParser::extractWhereColumns($sql);

        $this->assertContains('users.id', $columns);
        $this->assertContains('status', $columns);
    }

    public function test_sql_parser_extracts_join_columns(): void
    {
        $sql = 'SELECT * FROM users JOIN orders ON orders.user_id = users.id WHERE users.active = 1';
        $columns = SqlParser::extractJoinColumns($sql);

        $this->assertNotEmpty($columns);
        $this->assertTrue(
            in_array('orders.user_id', $columns) || in_array('users.id', $columns)
        );
    }

    public function test_sql_parser_extracts_order_by_columns(): void
    {
        $sql = 'SELECT * FROM users ORDER BY created_at DESC, id ASC LIMIT 10';
        $columns = SqlParser::extractOrderByColumns($sql);

        $this->assertCount(2, $columns);
        $this->assertSame('created_at DESC', $columns[0]);
        $this->assertSame('id ASC', $columns[1]);
    }

    public function test_sql_parser_detects_select_star(): void
    {
        $this->assertTrue(SqlParser::isSelectStar('SELECT * FROM users'));
        $this->assertFalse(SqlParser::isSelectStar('SELECT id, name FROM users'));
    }

    public function test_sql_parser_detects_primary_table(): void
    {
        $this->assertSame('users', SqlParser::detectPrimaryTable('SELECT * FROM users WHERE id = 1'));
        $this->assertSame('unknown', SqlParser::detectPrimaryTable('SHOW TABLES'));
    }

    // ---------------------------------------------------------------
    // ExecutionProfileAnalyzer (unit-level with hardcoded plans)
    // ---------------------------------------------------------------

    public function test_execution_profile_counts_nested_loop_depth(): void
    {
        $plan = "-> Nested loop inner join (cost=100 rows=10) (actual time=0.1..0.5 rows=10 loops=1)\n"
            ."  -> Nested loop inner join (cost=50 rows=5) (actual time=0.1..0.3 rows=5 loops=1)\n"
            ."    -> Index lookup on users (actual time=0.01..0.02 rows=1 loops=1)\n";

        $analyzer = new ExecutionProfileAnalyzer;
        $result = $analyzer->analyze($plan, ['rows_examined' => 0, 'has_early_termination' => false], []);
        $profile = $result['profile'];

        $this->assertSame(2, $profile->nestedLoopDepth);
    }

    public function test_execution_profile_classifies_scan_complexity_constant(): void
    {
        $plan = "-> Limit (actual time=0.01..0.02 rows=10 loops=1)\n";

        $analyzer = new ExecutionProfileAnalyzer;
        $result = $analyzer->analyze($plan, [
            'rows_examined' => 10,
            'has_early_termination' => true,
            'has_table_scan' => false,
            'complexity' => 'O(1)',
            'primary_access_type' => 'single_row_lookup',
        ], []);

        $this->assertSame(ComplexityClass::Constant, $result['profile']->scanComplexity);
    }

    public function test_execution_profile_classifies_sort_complexity(): void
    {
        $analyzer = new ExecutionProfileAnalyzer;

        $withFilesort = $analyzer->analyze('plan', ['rows_examined' => 0, 'has_filesort' => true], []);
        $this->assertSame(ComplexityClass::Linearithmic, $withFilesort['profile']->sortComplexity);

        $noFilesort = $analyzer->analyze('plan', ['rows_examined' => 0, 'has_filesort' => false], []);
        $this->assertSame(ComplexityClass::Constant, $noFilesort['profile']->sortComplexity);
    }

    public function test_execution_profile_deep_nesting_warning(): void
    {
        $plan = "-> Nested loop\n  -> Nested loop\n    -> Nested loop\n      -> Nested loop\n";

        $analyzer = new ExecutionProfileAnalyzer;
        $result = $analyzer->analyze($plan, ['rows_examined' => 0], []);

        $warnings = array_filter(
            $result['findings'],
            fn (Finding $f) => $f->severity === Severity::Warning && str_contains($f->title, 'nested loop')
        );

        $this->assertNotEmpty($warnings);
    }

    // ---------------------------------------------------------------
    // JoinAnalyzer (unit-level with hardcoded plans)
    // ---------------------------------------------------------------

    public function test_join_analyzer_detects_nested_loop(): void
    {
        $plan = "-> Nested loop inner join (actual time=0.1..1.0 rows=100 loops=1)\n";
        $analyzer = new JoinAnalyzer;
        $result = $analyzer->analyze($plan, [], []);

        $this->assertContains('nested_loop', $result['join_analysis']['join_types']);
    }

    public function test_join_analyzer_detects_hash_join(): void
    {
        $plan = "-> Hash join (actual time=0.5..2.0 rows=500 loops=1)\n";
        $analyzer = new JoinAnalyzer;
        $result = $analyzer->analyze($plan, [], []);

        $this->assertContains('hash_join', $result['join_analysis']['join_types']);

        $infoFindings = array_filter(
            $result['findings'],
            fn (Finding $f) => $f->severity === Severity::Info && str_contains($f->title, 'Hash join')
        );
        $this->assertNotEmpty($infoFindings);
    }

    public function test_join_analyzer_detects_fanout_explosion(): void
    {
        $plan = "-> Index lookup on small_table (actual time=0.01..0.02 rows=1 loops=1)\n"
            ."-> Table scan on big_table (actual time=0.1..1.0 rows=500 loops=1)\n";

        $analyzer = new JoinAnalyzer;
        $result = $analyzer->analyze($plan, [], []);

        $this->assertGreaterThan(1.0, $result['join_analysis']['fanout_multiplier']);
    }

    // ---------------------------------------------------------------
    // PlanStabilityAnalyzer
    // ---------------------------------------------------------------

    public function test_plan_stability_detects_optimizer_hints(): void
    {
        $sql = 'SELECT * FROM users FORCE INDEX (idx_name) WHERE name = "test"';
        $analyzer = new PlanStabilityAnalyzer;
        $result = $analyzer->analyze($sql, '', [], []);

        $this->assertContains('FORCE INDEX', $result['stability']['optimizer_hints']);

        $infoFindings = array_filter(
            $result['findings'],
            fn (Finding $f) => str_contains($f->title, 'Optimizer hints')
        );
        $this->assertNotEmpty($infoFindings);
    }

    public function test_plan_stability_detects_straight_join(): void
    {
        $sql = 'SELECT STRAIGHT_JOIN * FROM users JOIN orders ON orders.user_id = users.id';
        $analyzer = new PlanStabilityAnalyzer;
        $result = $analyzer->analyze($sql, '', [], []);

        $this->assertContains('STRAIGHT_JOIN', $result['stability']['optimizer_hints']);
    }

    public function test_plan_stability_detects_plan_flip_risk(): void
    {
        $plan = "-> Index lookup (cost=10 rows=100) (actual time=0.1..0.5 rows=1 loops=1)\n";
        $analyzer = new PlanStabilityAnalyzer;
        $result = $analyzer->analyze('SELECT 1', $plan, [], []);

        $this->assertTrue($result['stability']['plan_flip_risk']['is_risky']);

        $warnings = array_filter(
            $result['findings'],
            fn (Finding $f) => $f->severity === Severity::Warning && str_contains($f->title, 'Plan flip')
        );
        $this->assertNotEmpty($warnings);
    }

    public function test_plan_stability_no_risk_when_estimates_match(): void
    {
        $plan = "-> Index lookup (cost=10 rows=10) (actual time=0.1..0.5 rows=8 loops=1)\n";
        $analyzer = new PlanStabilityAnalyzer;
        $result = $analyzer->analyze('SELECT 1', $plan, [], []);

        $this->assertFalse($result['stability']['plan_flip_risk']['is_risky']);
    }

    // ---------------------------------------------------------------
    // RegressionSafetyAnalyzer
    // ---------------------------------------------------------------

    public function test_regression_safety_detects_type_conversion(): void
    {
        $plan = "-> Filter: (cast(users.id as double) = 1.0)\n";
        $analyzer = new RegressionSafetyAnalyzer;
        $result = $analyzer->analyze('SELECT * FROM users WHERE id = 1', $plan, [], []);

        $this->assertNotEmpty($result['safety']['type_conversions']);

        $warnings = array_filter(
            $result['findings'],
            fn (Finding $f) => $f->category === 'regression_safety'
        );
        $this->assertNotEmpty($warnings);
    }

    public function test_regression_safety_detects_charset_conversion(): void
    {
        $plan = "-> convert charset utf8mb4 to latin1\n";
        $analyzer = new RegressionSafetyAnalyzer;
        $result = $analyzer->analyze('SELECT 1', $plan, [], []);

        $this->assertTrue($result['safety']['has_charset_conversion']);
    }

    public function test_regression_safety_clean_plan(): void
    {
        $plan = "-> Index lookup on users using PRIMARY (actual time=0.01..0.02 rows=1 loops=1)\n";
        $analyzer = new RegressionSafetyAnalyzer;
        $result = $analyzer->analyze('SELECT * FROM users WHERE id = 1', $plan, [], []);

        $this->assertFalse($result['safety']['has_charset_conversion']);
        $this->assertEmpty($result['safety']['type_conversions']);
        $this->assertEmpty($result['findings']);
    }

    // ---------------------------------------------------------------
    // PlanNode: isConstAccess and isIoOperation
    // ---------------------------------------------------------------

    public function test_plan_node_is_const_access(): void
    {
        $constTypes = ['zero_row_const', 'const_row', 'single_row_lookup'];
        foreach ($constTypes as $type) {
            $node = new \QuerySentinel\Support\PlanNode(
                operation: "test $type",
                rawLine: '',
                accessType: $type,
            );
            $this->assertTrue($node->isConstAccess(), "$type should be const access");
        }

        $nonConstTypes = ['index_lookup', 'covering_index_lookup', 'table_scan', 'index_range_scan', 'index_scan', null];
        foreach ($nonConstTypes as $type) {
            $node = new \QuerySentinel\Support\PlanNode(
                operation: 'test',
                rawLine: '',
                accessType: $type,
            );
            $typeName = $type ?? 'null';
            $this->assertFalse($node->isConstAccess(), "$typeName should NOT be const access");
        }
    }

    public function test_plan_node_is_io_operation(): void
    {
        $ioTypes = ['table_scan', 'index_lookup', 'index_range_scan', 'covering_index_lookup',
            'single_row_lookup', 'index_scan', 'fulltext_index', 'const_row'];
        foreach ($ioTypes as $type) {
            $node = new \QuerySentinel\Support\PlanNode(
                operation: "test $type",
                rawLine: '',
                accessType: $type,
            );
            $this->assertTrue($node->isIoOperation(), "$type should be I/O operation");
        }

        // zero_row_const is NOT I/O
        $node = new \QuerySentinel\Support\PlanNode(
            operation: 'Zero rows',
            rawLine: '',
            accessType: 'zero_row_const',
        );
        $this->assertFalse($node->isIoOperation(), 'zero_row_const should NOT be I/O operation');

        // Non-I/O types
        $nonIoTypes = ['nested_loop', 'sort', 'filter', 'limit', 'materialize', null];
        foreach ($nonIoTypes as $type) {
            $node = new \QuerySentinel\Support\PlanNode(
                operation: 'test',
                rawLine: '',
                accessType: $type,
            );
            $typeName = $type ?? 'null';
            $this->assertFalse($node->isIoOperation(), "$typeName should NOT be I/O operation");
        }
    }

    public function test_plan_node_rows_processed(): void
    {
        $node = new \QuerySentinel\Support\PlanNode(
            operation: 'Index lookup on users',
            rawLine: '',
            actualRows: 50,
            loops: 100,
        );
        $this->assertSame(5000, $node->rowsProcessed());

        // Null values → 0
        $node2 = new \QuerySentinel\Support\PlanNode(
            operation: 'test',
            rawLine: '',
        );
        $this->assertSame(0, $node2->rowsProcessed());
    }

    public function test_plan_node_flatten(): void
    {
        $child1 = new \QuerySentinel\Support\PlanNode(operation: 'child1', rawLine: '');
        $child2 = new \QuerySentinel\Support\PlanNode(operation: 'child2', rawLine: '');
        $grandchild = new \QuerySentinel\Support\PlanNode(operation: 'grandchild', rawLine: '');
        $child1->children = [$grandchild];

        $root = new \QuerySentinel\Support\PlanNode(operation: 'root', rawLine: '');
        $root->children = [$child1, $child2];

        $flat = $root->flatten();
        $this->assertCount(4, $flat);
        $this->assertSame('root', $flat[0]->operation);
        $this->assertSame('child1', $flat[1]->operation);
        $this->assertSame('grandchild', $flat[2]->operation);
        $this->assertSame('child2', $flat[3]->operation);
    }

    // ---------------------------------------------------------------
    // ExecutionProfileAnalyzer: scan complexity from various access types
    // ---------------------------------------------------------------

    public function test_execution_profile_scan_complexity_from_precomputed_metrics(): void
    {
        $analyzer = new ExecutionProfileAnalyzer;

        $testCases = [
            ['complexity' => 'O(1)', 'expected' => ComplexityClass::Constant],
            ['complexity' => 'O(log n)', 'expected' => ComplexityClass::Logarithmic],
            ['complexity' => 'O(log n + k)', 'expected' => ComplexityClass::LogRange],
            ['complexity' => 'O(n)', 'expected' => ComplexityClass::Linear],
            ['complexity' => 'O(n log n)', 'expected' => ComplexityClass::Linearithmic],
            ['complexity' => 'O(n²)', 'expected' => ComplexityClass::Quadratic],
        ];

        foreach ($testCases as $case) {
            $result = $analyzer->analyze('plan text', [
                'rows_examined' => 0,
                'complexity' => $case['complexity'],
            ], []);

            $this->assertSame(
                $case['expected'],
                $result['profile']->scanComplexity,
                "Complexity {$case['complexity']} should map to {$case['expected']->name}"
            );
        }
    }

    public function test_execution_profile_scan_complexity_fallback_from_access_type(): void
    {
        $analyzer = new ExecutionProfileAnalyzer;

        // No pre-computed complexity → fallback to access type
        $result = $analyzer->analyze('plan', [
            'rows_examined' => 0,
            'primary_access_type' => 'zero_row_const',
        ], []);

        $this->assertSame(ComplexityClass::Constant, $result['profile']->scanComplexity);
    }

    public function test_execution_profile_scan_complexity_fallback_covering_index(): void
    {
        $analyzer = new ExecutionProfileAnalyzer;

        $result = $analyzer->analyze('plan', [
            'rows_examined' => 0,
            'primary_access_type' => 'covering_index_lookup',
        ], []);

        $this->assertSame(ComplexityClass::Logarithmic, $result['profile']->scanComplexity);
    }

    public function test_execution_profile_scan_complexity_fallback_index_range(): void
    {
        $analyzer = new ExecutionProfileAnalyzer;

        $result = $analyzer->analyze('plan', [
            'rows_examined' => 0,
            'primary_access_type' => 'index_range_scan',
        ], []);

        $this->assertSame(ComplexityClass::LogRange, $result['profile']->scanComplexity);
    }

    public function test_execution_profile_scan_complexity_fallback_table_scan(): void
    {
        $analyzer = new ExecutionProfileAnalyzer;

        $result = $analyzer->analyze('plan', [
            'rows_examined' => 0,
            'primary_access_type' => 'table_scan',
        ], []);

        $this->assertSame(ComplexityClass::Linear, $result['profile']->scanComplexity);
    }

    // ---------------------------------------------------------------
    // Backward Compatibility
    // ---------------------------------------------------------------

    public function test_backward_compatibility_report_unchanged(): void
    {
        $report = $this->createBaseReport();

        // All existing Report properties still work
        $this->assertSame('A', $report->grade);
        $this->assertTrue($report->passed);
        $this->assertSame(95.0, $report->compositeScore);
        $this->assertNotEmpty($report->summary);

        $array = $report->toArray();
        $this->assertArrayHasKey('grade', $array);
        $this->assertArrayHasKey('passed', $array);
        $this->assertArrayHasKey('composite_score', $array);
        $this->assertArrayHasKey('result', $array);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function createBaseReport(): Report
    {
        $result = new Result(
            sql: 'SELECT 1',
            driver: 'MySQL',
            explainRows: [],
            plan: '-> Rows fetched before execution (actual time=0.00..0.00 rows=1 loops=1)',
            metrics: [
                'execution_time_ms' => 0.01,
                'rows_examined' => 1,
                'rows_returned' => 1,
                'max_loops' => 1,
                'max_cost' => 0.0,
                'selectivity_ratio' => 1.0,
                'complexity_label' => 'LIMIT-optimized',
                'is_index_backed' => true,
                'has_covering_index' => true,
                'has_filesort' => false,
                'has_table_scan' => false,
                'has_temp_table' => false,
                'has_weedout' => false,
                'has_index_merge' => false,
                'has_disk_temp' => false,
                'has_early_termination' => true,
                'indexes_used' => [],
            ],
            scores: [
                'composite_score' => 95.0,
                'grade' => 'A',
                'breakdown' => [
                    'execution_time' => ['score' => 100, 'weight' => 0.30, 'weighted' => 30.0],
                    'scan_efficiency' => ['score' => 100, 'weight' => 0.25, 'weighted' => 25.0],
                    'index_quality' => ['score' => 100, 'weight' => 0.20, 'weighted' => 20.0],
                    'join_efficiency' => ['score' => 100, 'weight' => 0.15, 'weighted' => 15.0],
                    'scalability' => ['score' => 100, 'weight' => 0.10, 'weighted' => 10.0],
                ],
                'context_override' => false,
            ],
            findings: [],
            executionTimeMs: 0.01,
        );

        return new Report(
            result: $result,
            grade: 'A',
            passed: true,
            summary: 'Grade A | 0.01ms | 1 row | no issues',
            recommendations: [],
            compositeScore: 95.0,
            analyzedAt: new \DateTimeImmutable,
            scalability: [],
            mode: 'sql',
        );
    }

    /**
     * @param  Finding[]  $findings
     */
    private function createDiagnosticReport(array $findings): DiagnosticReport
    {
        return new DiagnosticReport(
            report: $this->createBaseReport(),
            findings: $findings,
        );
    }
}
