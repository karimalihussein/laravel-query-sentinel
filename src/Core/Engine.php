<?php

declare(strict_types=1);

namespace QuerySentinel\Core;

use QuerySentinel\Adapters\BuilderAdapter;
use QuerySentinel\Adapters\ClassMethodAdapter;
use QuerySentinel\Adapters\ProfilerAdapter;
use QuerySentinel\Analyzers\EnvironmentAnalyzer;
use QuerySentinel\Analyzers\ExecutionProfileAnalyzer;
use QuerySentinel\Analyzers\IndexCardinalityAnalyzer;
use QuerySentinel\Analyzers\JoinAnalyzer;
use QuerySentinel\Analyzers\PlanStabilityAnalyzer;
use QuerySentinel\Analyzers\RegressionSafetyAnalyzer;
use QuerySentinel\Contracts\AnalyzerInterface;
use QuerySentinel\Enums\Severity;
use QuerySentinel\Support\DiagnosticReport;
use QuerySentinel\Support\ExecutionGuard;
use QuerySentinel\Support\ExecutionProfile;
use QuerySentinel\Support\Finding;
use QuerySentinel\Support\Report;
use QuerySentinel\Support\SqlSanitizer;

/**
 * Unified entry point for all query analysis modes.
 *
 * Routes requests to the appropriate adapter:
 *   - analyzeSql()      → guard + sanitize + core analyzer
 *   - analyzeBuilder()   → extract SQL from Builder → core analyzer
 *   - profile()          → capture queries via DB::listen → core analyzer (each)
 *   - profileClass()     → resolve class + call method → profile()
 *
 * The Engine itself does NOT depend on Laravel. Adapter classes that
 * require Laravel (BuilderAdapter, ProfilerAdapter, ClassMethodAdapter)
 * are lazy-loaded only when their corresponding methods are called.
 */
final class Engine
{
    public function __construct(
        private readonly AnalyzerInterface $analyzer,
        private readonly ExecutionGuard $guard,
        private readonly SqlSanitizer $sanitizer,
    ) {}

    /**
     * Backward-compatible alias for analyzeSql().
     */
    public function analyze(string $sql): Report
    {
        return $this->analyzeSql($sql);
    }

    /**
     * Mode 1: Analyze raw SQL.
     *
     * Validates safety, sanitizes input, delegates to core analyzer.
     */
    public function analyzeSql(string $sql): Report
    {
        $sql = $this->sanitizer->sanitize($sql);
        $this->guard->validate($sql);

        return $this->analyzer->analyze($sql, 'sql');
    }

    /**
     * Mode 2: Analyze an Eloquent\Builder or Query\Builder.
     *
     * Extracts SQL and bindings from the builder without executing it,
     * interpolates bindings safely, then delegates to core analyzer.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>|\Illuminate\Database\Query\Builder  $builder
     */
    public function analyzeBuilder(object $builder): Report
    {
        return $this->createBuilderAdapter()->analyze($builder);
    }

    /**
     * Mode 3: Profile a closure, capturing all executed queries.
     *
     * Wraps execution in a transaction (rolled back after capture),
     * captures all queries via DB::listen, analyzes each SELECT
     * individually, and returns an aggregated ProfileReport.
     */
    public function profile(\Closure $callback): ProfileReport
    {
        return $this->createProfilerAdapter()->profile($callback);
    }

    /**
     * Mode 3b: Profile a class method invocation.
     *
     * Resolves the class from the Laravel container, calls the method
     * with the given arguments, and profiles all queries executed.
     *
     * @param  array<int, mixed>  $arguments
     */
    public function profileClass(string $class, string $method, array $arguments = []): ProfileReport
    {
        return $this->createClassMethodAdapter()->profile($class, $method, $arguments);
    }

    /**
     * Mode 5: Full diagnostic analysis (base report + deep analyzers).
     *
     * Runs the standard analyzeSql() pipeline, then adds deep analysis:
     * environment context, execution profiling, index cardinality,
     * join analysis, plan stability, regression safety, complexity
     * classification, and "Explain Why" insights.
     */
    public function diagnose(string $sql, ?string $connectionName = null): DiagnosticReport
    {
        $report = $this->analyzeSql($sql);
        $result = $report->result;
        $findings = [];

        // Convert existing rule-based findings to Finding DTOs
        foreach ($result->findings as $legacy) {
            $findings[] = Finding::fromLegacy($legacy);
        }

        // Section 1: Environment Context
        $envResult = (new EnvironmentAnalyzer)->analyze($connectionName);
        $environment = $envResult['context'];
        array_push($findings, ...$envResult['findings']);

        // Section 2: Execution Profile
        $execResult = (new ExecutionProfileAnalyzer)->analyze(
            $result->plan, $result->metrics, $result->explainRows, $connectionName
        );
        $executionProfile = $execResult['profile'];
        array_push($findings, ...$execResult['findings']);

        // Section 3: Index & Cardinality
        $indexResult = (new IndexCardinalityAnalyzer)->analyze(
            $result->sql, $result->metrics, $result->explainRows, $connectionName
        );
        $indexAnalysis = $indexResult['analysis'];
        array_push($findings, ...$indexResult['findings']);

        // Section 4: Join Analysis
        $joinResult = (new JoinAnalyzer)->analyze(
            $result->plan, $result->metrics, $result->explainRows
        );
        $joinAnalysis = $joinResult['join_analysis'];
        array_push($findings, ...$joinResult['findings']);

        // Section 5: Complexity Classification
        array_push($findings, ...$this->generateComplexityFindings($executionProfile));

        // Section 7: Plan Stability
        $stabilityResult = (new PlanStabilityAnalyzer)->analyze(
            $result->sql, $result->plan, $result->metrics, $result->explainRows, $connectionName
        );
        $stabilityAnalysis = $stabilityResult['stability'];
        array_push($findings, ...$stabilityResult['findings']);

        // Section 8: Regression Safety
        $safetyResult = (new RegressionSafetyAnalyzer)->analyze(
            $result->sql, $result->plan, $result->metrics, $result->explainRows
        );
        $safetyAnalysis = $safetyResult['safety'];
        array_push($findings, ...$safetyResult['findings']);

        // Section 10: Explain Why
        array_push($findings, ...$this->generateExplainWhy(
            $result->sql, $result->metrics, $executionProfile, $indexAnalysis, $joinAnalysis, $stabilityAnalysis
        ));

        // Sort findings by severity priority (CRITICAL first)
        usort($findings, fn (Finding $a, Finding $b) => $a->severity->priority() <=> $b->severity->priority());

        return new DiagnosticReport(
            report: $report,
            findings: $findings,
            environment: $environment,
            executionProfile: $executionProfile,
            indexAnalysis: $indexAnalysis,
            joinAnalysis: $joinAnalysis,
            stabilityAnalysis: $stabilityAnalysis,
            safetyAnalysis: $safetyAnalysis,
        );
    }

    /**
     * Generate complexity classification findings from ExecutionProfile.
     *
     * @return Finding[]
     */
    private function generateComplexityFindings(ExecutionProfile $profile): array
    {
        $findings = [];

        $scanRisk = $profile->scanComplexity->riskLevel();
        $findings[] = new Finding(
            severity: match ($scanRisk) {
                'HIGH' => Severity::Critical,
                'MEDIUM' => Severity::Optimization,
                default => Severity::Info,
            },
            category: 'complexity',
            title: sprintf('Scan complexity: %s', $profile->scanComplexity->value),
            description: $profile->scanComplexity->label(),
            metadata: ['scan_complexity' => $profile->scanComplexity->value, 'risk' => $scanRisk],
        );

        if ($profile->sortComplexity->value !== 'O(limit)') {
            $findings[] = new Finding(
                severity: Severity::Optimization,
                category: 'complexity',
                title: sprintf('Sort complexity: %s', $profile->sortComplexity->value),
                description: $profile->sortComplexity->label(),
                recommendation: 'Extend the driving index to include ORDER BY columns for index-backed sort.',
                metadata: ['sort_complexity' => $profile->sortComplexity->value],
            );
        }

        return $findings;
    }

    /**
     * Generate human-readable "Explain Why" insights.
     *
     * @return Finding[]
     */
    private function generateExplainWhy(
        string $rawSql,
        array $metrics,
        ?ExecutionProfile $profile,
        ?array $indexAnalysis,
        ?array $joinAnalysis,
        ?array $stability,
    ): array {
        $findings = [];

        // Why this index was chosen
        $indexesUsed = $metrics['indexes_used'] ?? [];
        if (! empty($indexesUsed)) {
            $indexName = $indexesUsed[0];
            $description = sprintf(
                'MySQL chose index `%s` because it best matches the WHERE/JOIN columns in leftmost-prefix order.',
                $indexName
            );

            if ($indexAnalysis !== null) {
                foreach ($indexAnalysis as $table => $indexes) {
                    if (isset($indexes[$indexName]) && ($indexes[$indexName]['is_used'] ?? false)) {
                        $columns = $indexes[$indexName]['columns'] ?? [];
                        ksort($columns);
                        $colNames = array_column($columns, 'column');
                        $description .= sprintf(' Index covers columns: (%s).', implode(', ', $colNames));
                        break;
                    }
                }
            }

            $findings[] = new Finding(
                severity: Severity::Info,
                category: 'explain_why',
                title: sprintf('Index choice: %s', $indexName),
                description: $description,
            );
        }

        // Why filesort / no filesort
        if ($metrics['has_filesort'] ?? false) {
            $findings[] = new Finding(
                severity: Severity::Info,
                category: 'explain_why',
                title: 'Why filesort is needed',
                description: 'The ORDER BY columns are not a suffix of the driving index. MySQL must materialize matching rows, then sort them.',
                recommendation: 'Extend the driving index to include ORDER BY columns as a tail.',
            );
        } elseif ($metrics['is_index_backed'] ?? false) {
            $findings[] = new Finding(
                severity: Severity::Info,
                category: 'explain_why',
                title: 'Sort is index-backed',
                description: 'The index naturally produces rows in ORDER BY order, avoiding a separate sort step. This is optimal.',
            );
        }

        // Early termination explanation
        if ($metrics['has_early_termination'] ?? false) {
            $findings[] = new Finding(
                severity: Severity::Info,
                category: 'explain_why',
                title: 'LIMIT early termination active',
                description: 'MySQL stops reading from the driving index after finding enough rows for the LIMIT. The optimizer estimated many more rows, but LIMIT bounds the actual work.',
            );
        }

        // Join order
        if ($profile !== null && $profile->nestedLoopDepth > 0) {
            $findings[] = new Finding(
                severity: Severity::Info,
                category: 'explain_why',
                title: sprintf('Join order: %d nested loop level(s)', $profile->nestedLoopDepth),
                description: 'MySQL processes joins in nested loop order. The outermost table is the driving table (smallest estimated result set after filtering).',
            );
        }

        // Query summary
        $summary = $this->generateQuerySummary($rawSql);
        if ($summary !== null) {
            $findings[] = new Finding(
                severity: Severity::Info,
                category: 'explain_why',
                title: 'What this query does',
                description: $summary,
            );
        }

        // Top recommendation
        $topRec = $this->identifyTopRecommendation($metrics, $profile, $stability);
        if ($topRec !== null) {
            $findings[] = new Finding(
                severity: Severity::Info,
                category: 'explain_why',
                title: 'Top recommendation',
                description: $topRec,
            );
        }

        return $findings;
    }

    private function generateQuerySummary(string $rawSql): ?string
    {
        $tables = [];
        if (preg_match('/\bFROM\s+`?(\w+)`?/i', $rawSql, $match)) {
            $tables[] = $match[1];
        }
        if (preg_match_all('/\bJOIN\s+`?(\w+)`?/i', $rawSql, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }

        if (empty($tables)) {
            return null;
        }

        $hasWhere = (bool) preg_match('/\bWHERE\b/i', $rawSql);
        $hasOrderBy = (bool) preg_match('/\bORDER\s+BY\b/i', $rawSql);
        $hasLimit = (bool) preg_match('/\bLIMIT\b/i', $rawSql);
        $hasExists = (bool) preg_match('/\bEXISTS\b/i', $rawSql);

        $parts = [sprintf('Selects from %s', implode(' joined with ', $tables))];
        if ($hasWhere) {
            $parts[] = 'with filtering conditions';
        }
        if ($hasExists) {
            $parts[] = 'using EXISTS subqueries for relationship checks';
        }
        if ($hasOrderBy) {
            $parts[] = 'ordered by specified columns';
        }
        if ($hasLimit) {
            $parts[] = 'limited to a page of results';
        }

        return implode(', ', $parts).'.';
    }

    private function identifyTopRecommendation(
        array $metrics,
        ?ExecutionProfile $profile,
        ?array $stability,
    ): ?string {
        if ($metrics['has_table_scan'] ?? false) {
            return 'Add an index on the scanned table. A full table scan is the single biggest performance bottleneck.';
        }
        if ($metrics['has_weedout'] ?? false) {
            return 'Eliminate weedout by using EXISTS semi-joins or denormalized filter tables.';
        }
        if ($metrics['has_filesort'] ?? false) {
            return 'Extend the driving index to cover ORDER BY columns. Eliminating filesort enables LIMIT early termination.';
        }
        if ($stability !== null && ($stability['plan_flip_risk']['is_risky'] ?? false)) {
            return 'Run ANALYZE TABLE to update optimizer statistics. Stale statistics may cause suboptimal plan choices.';
        }
        if (! ($metrics['has_covering_index'] ?? false) && ($metrics['is_index_backed'] ?? false)) {
            return 'Extend the index to include SELECT columns for a covering-index optimization.';
        }

        return null;
    }

    /**
     * Access the core analyzer for direct use.
     */
    public function getAnalyzer(): AnalyzerInterface
    {
        return $this->analyzer;
    }

    public function getGuard(): ExecutionGuard
    {
        return $this->guard;
    }

    public function getSanitizer(): SqlSanitizer
    {
        return $this->sanitizer;
    }

    private function createBuilderAdapter(): BuilderAdapter
    {
        return new BuilderAdapter($this->analyzer, $this->guard, $this->sanitizer);
    }

    private function createProfilerAdapter(): ProfilerAdapter
    {
        return new ProfilerAdapter($this->analyzer, $this->guard, $this->sanitizer);
    }

    private function createClassMethodAdapter(): ClassMethodAdapter
    {
        return new ClassMethodAdapter(
            new ProfilerAdapter($this->analyzer, $this->guard, $this->sanitizer),
        );
    }
}
