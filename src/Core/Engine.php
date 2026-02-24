<?php

declare(strict_types=1);

namespace QuerySentinel\Core;

use QuerySentinel\Adapters\BuilderAdapter;
use QuerySentinel\Adapters\ClassMethodAdapter;
use QuerySentinel\Adapters\ProfilerAdapter;
use QuerySentinel\Analyzers\AntiPatternAnalyzer;
use QuerySentinel\Analyzers\CardinalityDriftAnalyzer;
use QuerySentinel\Analyzers\ConcurrencyRiskAnalyzer;
use QuerySentinel\Analyzers\EnvironmentAnalyzer;
use QuerySentinel\Analyzers\ExecutionProfileAnalyzer;
use QuerySentinel\Analyzers\HypotheticalIndexAnalyzer;
use QuerySentinel\Analyzers\IndexCardinalityAnalyzer;
use QuerySentinel\Analyzers\IndexSynthesisAnalyzer;
use QuerySentinel\Analyzers\JoinAnalyzer;
use QuerySentinel\Analyzers\MemoryPressureAnalyzer;
use QuerySentinel\Analyzers\PlanStabilityAnalyzer;
use QuerySentinel\Analyzers\RegressionBaselineAnalyzer;
use QuerySentinel\Analyzers\RegressionSafetyAnalyzer;
use QuerySentinel\Analyzers\WorkloadAnalyzer;
use QuerySentinel\Contracts\AnalyzerInterface;
use QuerySentinel\Contracts\DriverInterface;
use QuerySentinel\Enums\Severity;
use QuerySentinel\Scoring\ConfidenceScorer;
use QuerySentinel\Support\DiagnosticReport;
use QuerySentinel\Support\EngineConsistencyValidator;
use QuerySentinel\Support\ExecutionGuard;
use QuerySentinel\Support\ExecutionProfile;
use QuerySentinel\Support\Finding;
use QuerySentinel\Support\Report;
use QuerySentinel\Support\SqlParser;
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
        private readonly ?CardinalityDriftAnalyzer $cardinalityDriftAnalyzer = null,
        private readonly ?AntiPatternAnalyzer $antiPatternAnalyzer = null,
        private readonly ?IndexSynthesisAnalyzer $indexSynthesisAnalyzer = null,
        private readonly ?ConfidenceScorer $confidenceScorer = null,
        private readonly ?ConcurrencyRiskAnalyzer $concurrencyRiskAnalyzer = null,
        private readonly ?MemoryPressureAnalyzer $memoryPressureAnalyzer = null,
        private readonly ?RegressionBaselineAnalyzer $regressionBaselineAnalyzer = null,
        private readonly ?HypotheticalIndexAnalyzer $hypotheticalIndexAnalyzer = null,
        private readonly ?DriverInterface $driver = null,
        private readonly ?WorkloadAnalyzer $workloadAnalyzer = null,
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
     * Runs the standard analyzeSql() pipeline, then adds deep analysis
     * from all 12 phases: environment, execution profiling, index cardinality,
     * cardinality drift, join analysis, anti-patterns, index synthesis,
     * confidence scoring, concurrency risk, memory pressure, plan stability,
     * regression safety, regression baselines, hypothetical indexes,
     * complexity classification, and "Explain Why" insights.
     *
     * Post-collection: access-type suppression, finding deduplication,
     * and consistency validation.
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

        // 1. Environment Context
        $envResult = (new EnvironmentAnalyzer)->analyze($connectionName);
        $environment = $envResult['context'];
        array_push($findings, ...$envResult['findings']);

        // 2. Execution Profile
        $execResult = (new ExecutionProfileAnalyzer)->analyze(
            $result->plan, $result->metrics, $result->explainRows, $connectionName
        );
        $executionProfile = $execResult['profile'];
        array_push($findings, ...$execResult['findings']);

        // 3. Index & Cardinality
        $indexResult = (new IndexCardinalityAnalyzer)->analyze(
            $result->sql, $result->metrics, $result->explainRows, $connectionName
        );
        $indexAnalysis = $indexResult['analysis'];
        array_push($findings, ...$indexResult['findings']);

        // 4. Cardinality Drift (Phase 1)
        $cardinalityDrift = null;
        if ($this->cardinalityDriftAnalyzer !== null) {
            $driftResult = $this->cardinalityDriftAnalyzer->analyze($result->metrics, $result->explainRows);
            $cardinalityDrift = $driftResult['cardinality_drift'];
            array_push($findings, ...$driftResult['findings']);
        }

        // 5. Join Analysis (enhanced Phase 2)
        $joinResult = (new JoinAnalyzer)->analyze(
            $result->plan, $result->metrics, $result->explainRows
        );
        $joinAnalysis = $joinResult['join_analysis'];
        array_push($findings, ...$joinResult['findings']);

        // 6. Anti-Pattern Analysis (Phase 3)
        $antiPatterns = null;
        if ($this->antiPatternAnalyzer !== null) {
            $apResult = $this->antiPatternAnalyzer->analyze($result->sql, $result->metrics);
            $antiPatterns = $apResult['anti_patterns'];
            array_push($findings, ...$apResult['findings']);
        }

        // 7. Index Synthesis (Phase 4)
        $indexSynthesis = null;
        if ($this->indexSynthesisAnalyzer !== null) {
            $isResult = $this->indexSynthesisAnalyzer->analyze(
                $result->sql, $result->metrics, $indexAnalysis, $cardinalityDrift
            );
            $indexSynthesis = $isResult['index_synthesis'];
            array_push($findings, ...$isResult['findings']);
        }

        // 8. Memory Pressure (Phase 7)
        $memoryPressure = null;
        if ($this->memoryPressureAnalyzer !== null) {
            $mpResult = $this->memoryPressureAnalyzer->analyze($result->metrics, $environment, $executionProfile);
            $memoryPressure = $mpResult['memory_pressure'];
            array_push($findings, ...$mpResult['findings']);
        }

        // 9. Concurrency Risk (Phase 6)
        $concurrencyRisk = null;
        if ($this->concurrencyRiskAnalyzer !== null) {
            $crResult = $this->concurrencyRiskAnalyzer->analyze($result->sql, $result->metrics, $executionProfile);
            $concurrencyRisk = $crResult['concurrency'];
            array_push($findings, ...$crResult['findings']);
        }

        // 10. Plan Stability (enhanced Phase 8)
        $stabilityResult = (new PlanStabilityAnalyzer)->analyze(
            $result->sql, $result->plan, $result->metrics, $result->explainRows, $connectionName, $cardinalityDrift
        );
        $stabilityAnalysis = $stabilityResult['stability'];
        array_push($findings, ...$stabilityResult['findings']);

        // 11. Regression Safety (existing)
        $safetyResult = (new RegressionSafetyAnalyzer)->analyze(
            $result->sql, $result->plan, $result->metrics, $result->explainRows
        );
        $safetyAnalysis = $safetyResult['safety'];
        array_push($findings, ...$safetyResult['findings']);

        // 12. Confidence Score (Phase 5)
        $confidence = null;
        if ($this->confidenceScorer !== null) {
            $confResult = $this->confidenceScorer->score(
                $result->metrics,
                $cardinalityDrift,
                $stabilityAnalysis,
                $environment,
                $this->driver?->supportsAnalyze() ?? false,
            );
            $confidence = $confResult['confidence'];
            array_push($findings, ...$confResult['findings']);
        }

        // 13. Regression Baselines (Phase 9)
        $regression = null;
        if ($this->regressionBaselineAnalyzer !== null) {
            $regResult = $this->regressionBaselineAnalyzer->analyze($result->sql, [
                'composite_score' => $report->compositeScore,
                'grade' => $report->grade,
                'execution_time_ms' => $result->executionTimeMs,
                'rows_examined' => $result->metrics['rows_examined'] ?? 0,
                'complexity' => $result->metrics['complexity'] ?? 'unknown',
                'primary_access_type' => $result->metrics['primary_access_type'] ?? null,
                'indexes_used' => $result->metrics['indexes_used'] ?? [],
                'finding_counts' => array_count_values(array_map(fn (Finding $f) => $f->severity->value, $findings)),
                'rows_returned' => $result->metrics['rows_returned'] ?? 0,
                'buffer_pool_utilization' => $environment->bufferPoolUtilization,
                'is_cold_cache' => $environment->isColdCache,
            ]);
            $regression = $regResult['regression'];

            // Intentional scan + regression: downgrade severity to INFO
            if ($result->metrics['is_intentional_scan'] ?? false) {
                foreach ($regResult['findings'] as $regFinding) {
                    $findings[] = new Finding(
                        severity: Severity::Info,
                        category: $regFinding->category,
                        title: $regFinding->title,
                        description: $regFinding->description,
                        recommendation: $regFinding->recommendation,
                        metadata: $regFinding->metadata,
                    );
                }
            } else {
                array_push($findings, ...$regResult['findings']);
            }
        }

        // 14. Hypothetical Index Simulation (Phase 10)
        $hypotheticalIndexes = null;
        if ($this->hypotheticalIndexAnalyzer !== null && $this->driver !== null) {
            $hiResult = $this->hypotheticalIndexAnalyzer->analyze(
                $result->sql,
                $indexSynthesis,
                $this->driver,
                app()->environment(),
            );
            $hypotheticalIndexes = $hiResult['hypothetical_indexes'];
            array_push($findings, ...$hiResult['findings']);
        }

        // 14b. Workload-Level Modeling
        $workload = null;
        if ($this->workloadAnalyzer !== null) {
            $wlResult = $this->workloadAnalyzer->analyze($sql, [
                'rows_returned' => $result->metrics['rows_returned'] ?? 0,
                'rows_examined' => $result->metrics['rows_examined'] ?? 0,
            ]);
            $workload = $wlResult['workload'];
            array_push($findings, ...$wlResult['findings']);
        }

        // 15. Complexity Classification (context-aware)
        array_push($findings, ...$this->generateComplexityFindings($executionProfile, $result->sql));

        // 16. Root-Cause Detection (before ExplainWhy so top recommendation is root-cause-aware)
        $rootCauses = $this->detectRootCauses($findings, $result->metrics, $result->sql);

        // 17. Explain Why (root-cause-aware)
        array_push($findings, ...$this->generateExplainWhy(
            $result->sql, $result->metrics, $executionProfile, $indexAnalysis, $joinAnalysis, $stabilityAnalysis, $rootCauses
        ));

        // 18. Root-Cause Suppression — remove generic index recs when root cause is function/wildcard
        $findings = $this->suppressByRootCause($findings, $rootCauses, $result->sql);

        // 19. Access-Type Suppression — remove contradictory findings for optimal access
        $findings = $this->suppressForOptimalAccess($findings, $result->metrics, $result->sql);

        // 20. Finding Deduplication — merge overlapping recommendations
        $findings = $this->deduplicateFindings($findings);

        // 21. Confidence-Gated Severity — downgrade findings when confidence is low.
        // Low confidence (<50%): Critical → Warning, Warning → Optimization
        // Moderate confidence (<70%): Critical → Warning
        $confidenceOverall = $confidence['overall'] ?? 1.0;
        if ($confidenceOverall < 0.5) {
            $findings = array_map(function (Finding $f) {
                if ($f->severity === Severity::Critical) {
                    return new Finding(Severity::Warning, $f->category, $f->title.' [low confidence]', $f->description, $f->recommendation, $f->metadata);
                }
                if ($f->severity === Severity::Warning) {
                    return new Finding(Severity::Optimization, $f->category, $f->title.' [low confidence]', $f->description, $f->recommendation, $f->metadata);
                }

                return $f;
            }, $findings);
        } elseif ($confidenceOverall < 0.7) {
            $findings = array_map(function (Finding $f) {
                if ($f->severity === Severity::Critical) {
                    return new Finding(Severity::Warning, $f->category, $f->title.' [moderate confidence]', $f->description, $f->recommendation, $f->metadata);
                }

                return $f;
            }, $findings);
        }

        // 22. Consistency Validation — log-only, no throw
        $validator = new EngineConsistencyValidator;
        $validation = $validator->validate($result->metrics, $findings, $concurrencyRisk, $result->sql);
        if (! $validation['valid'] && function_exists('app') && app()->bound('log')) {
            foreach ($validation['violations'] as $violation) {
                app('log')->warning('[QuerySentinel] Consistency violation: '.$violation);
            }
        }

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
            cardinalityDrift: $cardinalityDrift,
            antiPatterns: $antiPatterns,
            indexSynthesis: $indexSynthesis,
            confidence: $confidence,
            concurrencyRisk: $concurrencyRisk,
            memoryPressure: $memoryPressure,
            regression: $regression,
            hypotheticalIndexes: $hypotheticalIndexes,
            workload: $workload,
        );
    }

    /**
     * Generate complexity classification findings from ExecutionProfile.
     *
     * Context-aware: only recommends ORDER BY index optimization when
     * the query actually contains an ORDER BY clause.
     *
     * @return Finding[]
     */
    private function generateComplexityFindings(ExecutionProfile $profile, string $rawSql): array
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

        $hasOrderBy = (bool) preg_match('/\bORDER\s+BY\b/i', $rawSql);

        if ($profile->sortComplexity->value !== 'O(limit)' && $hasOrderBy) {
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
     * Context-aware: checks for ORDER BY presence before filesort
     * recommendations, and for SELECT * before covering index suggestions.
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
        array $rootCauses = [],
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

        // Why filesort / no filesort — context-aware ORDER BY check
        $hasOrderBy = (bool) preg_match('/\bORDER\s+BY\b/i', $rawSql);

        if (($metrics['has_filesort'] ?? false) && $hasOrderBy) {
            $findings[] = new Finding(
                severity: Severity::Info,
                category: 'explain_why',
                title: 'Why filesort is needed',
                description: 'The ORDER BY columns are not a suffix of the driving index. MySQL must materialize matching rows, then sort them.',
                recommendation: 'Extend the driving index to include ORDER BY columns as a tail.',
            );
        } elseif (($metrics['has_filesort'] ?? false) && ! $hasOrderBy) {
            $findings[] = new Finding(
                severity: Severity::Info,
                category: 'explain_why',
                title: 'Why filesort is needed',
                description: 'MySQL performs an internal sort (possibly for GROUP BY, DISTINCT, or query optimization). This is not caused by an ORDER BY clause.',
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
            if ($metrics['is_intentional_scan'] ?? false) {
                $summary .= ' This is a full dataset retrieval with no filtering — the full scan is expected behavior.';
            }
            $findings[] = new Finding(
                severity: Severity::Info,
                category: 'explain_why',
                title: 'What this query does',
                description: $summary,
            );
        }

        // Top recommendation — context-aware + root-cause-aware
        $topRec = $this->identifyTopRecommendation($metrics, $profile, $stability, $rawSql, $rootCauses);
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

    /**
     * Context-aware top recommendation: checks for ORDER BY presence
     * before filesort suggestions, and for SELECT * before covering index.
     */
    private function identifyTopRecommendation(
        array $metrics,
        ?ExecutionProfile $profile,
        ?array $stability,
        string $rawSql,
        array $rootCauses = [],
    ): ?string {
        // Root-cause-specific recommendations override generic table scan advice
        if (in_array('function_on_column', $rootCauses, true)) {
            $funcInfo = SqlParser::detectFunctionsOnColumns($rawSql);
            if (! empty($funcInfo)) {
                $func = $funcInfo[0]['function'];
                $col = $funcInfo[0]['column'];
                $table = SqlParser::detectPrimaryTable($rawSql);

                return sprintf(
                    'Remove %s() wrapping from column `%s`. Use case-insensitive collation, or create a functional index: CREATE INDEX idx_%s_%s ON %s((%s(%s))).',
                    $func, $col, strtolower($table), strtolower($col), $table, $func, $col
                );
            }
        }

        if (in_array('leading_wildcard', $rootCauses, true)) {
            return 'Use a FULLTEXT index for prefix-independent search, or create a generated reverse column with ALTER TABLE ADD COLUMN col_rev VARCHAR(255) GENERATED ALWAYS AS (REVERSE(col)) STORED.';
        }

        if (in_array('intentional_scan', $rootCauses, true)) {
            return 'This query reads the entire dataset by design. Add LIMIT if not all rows are required, or use pagination (Laravel chunk()/cursor()) for large exports.';
        }

        if ($metrics['has_table_scan'] ?? false) {
            return 'Add an index on the scanned table. A full table scan is the single biggest performance bottleneck.';
        }
        if ($metrics['has_weedout'] ?? false) {
            return 'Eliminate weedout by using EXISTS semi-joins or denormalized filter tables.';
        }

        $hasOrderBy = (bool) preg_match('/\bORDER\s+BY\b/i', $rawSql);
        if (($metrics['has_filesort'] ?? false) && $hasOrderBy) {
            return 'Extend the driving index to cover ORDER BY columns. Eliminating filesort enables LIMIT early termination.';
        }

        if ($stability !== null && ($stability['plan_flip_risk']['is_risky'] ?? false)) {
            return 'Run ANALYZE TABLE to update optimizer statistics. Stale statistics may cause suboptimal plan choices.';
        }

        if (! ($metrics['has_covering_index'] ?? false) && ($metrics['is_index_backed'] ?? false)) {
            if (SqlParser::isSelectStar($rawSql)) {
                return 'Replace SELECT * with explicit column names to enable covering-index optimization and reduce I/O.';
            }

            return 'Extend the index to include SELECT columns for a covering-index optimization.';
        }

        return null;
    }

    /**
     * Detect root causes from anti-pattern findings and metrics.
     *
     * Root causes explain WHY an index is missing or a table scan occurs.
     * When a root cause is identified, generic index recommendations are
     * misleading because a plain B-tree index won't help.
     *
     * @param  Finding[]  $findings
     * @param  array<string, mixed>  $metrics
     * @return string[]
     */
    private function detectRootCauses(array $findings, array $metrics, string $sql): array
    {
        // Intentional full scan: root cause is deliberate — suppress ALL generic index advice
        if ($metrics['is_intentional_scan'] ?? false) {
            return ['intentional_scan'];
        }

        $rootCauses = [];
        $isUnindexed = ($metrics['has_table_scan'] ?? false)
            || ($metrics['primary_access_type'] ?? null) === 'table_scan';

        foreach ($findings as $finding) {
            if ($finding->category !== 'anti_pattern') {
                continue;
            }

            if (isset($finding->metadata['function']) && $isUnindexed) {
                $rootCauses[] = 'function_on_column';
            }

            if (str_contains($finding->title, 'Leading wildcard') && $isUnindexed) {
                $rootCauses[] = 'leading_wildcard';
            }

            if (str_contains($finding->title, 'OR chain') && $isUnindexed) {
                $rootCauses[] = 'or_chain';
            }
        }

        if ($isUnindexed && empty($rootCauses)) {
            $rootCauses[] = 'missing_index';
        }

        return array_unique($rootCauses);
    }

    /**
     * Suppress generic index findings when a specific root cause is identified.
     *
     * When function wrapping or leading wildcard is the root cause, generic
     * "create an index" findings are misleading because a standard B-tree
     * index won't help when the column is wrapped in a function.
     *
     * @param  Finding[]  $findings
     * @param  string[]  $rootCauses
     * @return Finding[]
     */
    private function suppressByRootCause(array $findings, array $rootCauses, string $sql): array
    {
        $suppressGenericIndex = in_array('function_on_column', $rootCauses, true)
            || in_array('leading_wildcard', $rootCauses, true)
            || in_array('intentional_scan', $rootCauses, true);

        if (! $suppressGenericIndex) {
            return $findings;
        }

        return array_values(array_filter($findings, function (Finding $f): bool {
            // Suppress NoIndexRule (category 'no_index' from BaseRule::key())
            if ($f->category === 'no_index' || ($f->category === 'rule' && stripos($f->title, 'No index') !== false)) {
                return false;
            }

            // Suppress FullTableScanRule (category 'full_table_scan' from BaseRule::key())
            if ($f->category === 'full_table_scan' || ($f->category === 'rule' && stripos($f->title, 'Full table scan') !== false)) {
                return false;
            }

            return true;
        }));
    }

    /**
     * Suppress findings that are inappropriate for optimal access types.
     *
     * When access type is CONST/EQ_REF (zero_row_const, const_row, single_row_lookup):
     * - Remove all index synthesis findings (already optimal)
     * - Remove index-related rule findings (no index needed)
     * - Remove ORDER BY recommendations when no ORDER BY in SQL
     * - Remove covering index recommendations when SELECT *
     *
     * @param  Finding[]  $findings
     * @param  array<string, mixed>  $metrics
     * @return Finding[]
     */
    private function suppressForOptimalAccess(array $findings, array $metrics, string $rawSql): array
    {
        $accessType = $metrics['primary_access_type'] ?? null;
        $isOptimal = in_array($accessType, ['zero_row_const', 'const_row', 'single_row_lookup'], true);

        if (! $isOptimal) {
            return $findings;
        }

        $hasOrderBy = (bool) preg_match('/\bORDER\s+BY\b/i', $rawSql);

        return array_values(array_filter($findings, function (Finding $f) use ($hasOrderBy): bool {
            // Suppress all index synthesis findings
            if ($f->category === 'index_synthesis') {
                return false;
            }

            // Suppress index-related rule findings (category is key name from BaseRule)
            if (in_array($f->category, ['rule', 'no_index', 'index_merge'], true) && stripos($f->title, 'index') !== false) {
                return false;
            }

            // Suppress ORDER BY recommendations when no ORDER BY
            if (! $hasOrderBy && $f->recommendation !== null && stripos($f->recommendation, 'ORDER BY') !== false) {
                return false;
            }

            return true;
        }));
    }

    /**
     * Deduplicate findings to prevent overlapping recommendations.
     *
     * Pass 1: Identical recommendation text → keep highest severity.
     * Pass 2: NoIndexRule + IndexSynthesis for same table → keep IndexSynthesis.
     * Pass 3: FullTableScanRule + NoIndexRule both fire → keep NoIndexRule.
     *
     * @param  Finding[]  $findings
     * @return Finding[]
     */
    private function deduplicateFindings(array $findings): array
    {
        // Pass 1: Deduplicate identical recommendation text — keep highest severity
        $byRecommendation = [];
        $nonActionable = [];

        foreach ($findings as $finding) {
            if ($finding->recommendation === null) {
                $nonActionable[] = $finding;

                continue;
            }

            $key = $finding->recommendation;
            if (! isset($byRecommendation[$key]) || $finding->severity->priority() < $byRecommendation[$key]->severity->priority()) {
                $byRecommendation[$key] = $finding;
            }
        }

        $deduplicated = array_merge($nonActionable, array_values($byRecommendation));

        // Pass 2: NoIndexRule vs IndexSynthesis for same table
        $indexSynthesisTables = [];
        foreach ($deduplicated as $finding) {
            if ($finding->category === 'index_synthesis') {
                $table = $finding->metadata['table'] ?? null;
                if ($table !== null) {
                    $indexSynthesisTables[$table] = true;
                }
            }
        }

        if ($indexSynthesisTables !== []) {
            $deduplicated = array_values(array_filter($deduplicated, function (Finding $f) use ($indexSynthesisTables): bool {
                if (in_array($f->category, ['rule', 'no_index'], true) && stripos($f->title, 'No index') !== false) {
                    $tables = $f->metadata['tables_accessed'] ?? [];
                    foreach ($tables as $table) {
                        if (isset($indexSynthesisTables[$table])) {
                            return false;
                        }
                    }
                }

                return true;
            }));
        }

        // Pass 3: FullTableScanRule + NoIndexRule both fire → keep NoIndexRule
        $hasNoIndexRule = false;
        foreach ($deduplicated as $finding) {
            if (in_array($finding->category, ['rule', 'no_index'], true) && stripos($finding->title, 'No index') !== false) {
                $hasNoIndexRule = true;
                break;
            }
        }

        if ($hasNoIndexRule) {
            $deduplicated = array_values(array_filter($deduplicated, function (Finding $f): bool {
                return ! (in_array($f->category, ['rule', 'full_table_scan'], true) && stripos($f->title, 'Full table scan') !== false);
            }));
        }

        return $deduplicated;
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
