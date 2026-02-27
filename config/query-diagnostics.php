<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Database Driver
    |--------------------------------------------------------------------------
    |
    | The database driver used for EXPLAIN analysis.
    | Supported: "mysql", "pgsql"
    |
    */

    'driver' => env('QUERY_SENTINEL_DRIVER', 'mysql'),

    /*
    |--------------------------------------------------------------------------
    | Database Connection
    |--------------------------------------------------------------------------
    |
    | The database connection name to use. Null uses the default connection.
    |
    */

    'connection' => env('QUERY_SENTINEL_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Validation Strict Mode
    |--------------------------------------------------------------------------
    |
    | When true (default): fail-safe pipeline — validate syntax, schema, joins
    | before EXPLAIN. Abort on any failure. No fake scoring.
    | When false: legacy behavior — run EXPLAIN directly, allow analysis even
    | when EXPLAIN returns error (e.g. for SQLite in tests).
    |
    */

    'validation' => [
        'strict' => env('QUERY_SENTINEL_VALIDATION_STRICT', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scoring Configuration
    |--------------------------------------------------------------------------
    |
    | Weights determine how each component contributes to the composite score.
    | All weights must sum to 1.0.
    |
    */

    'scoring' => [

        'weights' => [
            'execution_time' => 0.30,
            'scan_efficiency' => 0.25,
            'index_quality' => 0.20,
            'join_efficiency' => 0.15,
            'scalability' => 0.10,
        ],

        'grade_thresholds' => [
            'A' => 90,
            'B' => 75,
            'C' => 50,
            'D' => 25,
            'F' => 0,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Rules Configuration
    |--------------------------------------------------------------------------
    |
    | Register rule classes to evaluate against query metrics.
    | Each class must implement QuerySentinel\Contracts\RuleInterface.
    |
    */

    'rules' => [

        'enabled' => [
            \QuerySentinel\Rules\FullTableScanRule::class,
            \QuerySentinel\Rules\TempTableRule::class,
            \QuerySentinel\Rules\WeedoutRule::class,
            \QuerySentinel\Rules\DeepNestedLoopRule::class,
            \QuerySentinel\Rules\IndexMergeRule::class,
            \QuerySentinel\Rules\StaleStatsRule::class,
            \QuerySentinel\Rules\LimitIneffectiveRule::class,
            \QuerySentinel\Rules\QuadraticComplexityRule::class,
            \QuerySentinel\Rules\NoIndexRule::class,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Thresholds
    |--------------------------------------------------------------------------
    |
    | Default thresholds for performance metrics. Rules reference these
    | values to determine when to generate warnings or critical findings.
    |
    */

    'thresholds' => [
        'max_execution_time_ms' => 1000,
        'max_rows_examined' => 100_000,
        'max_loops' => 10_000,
        'max_cost' => 1_000_000,
        'max_nested_loop_depth' => 4,
    ],

    /*
    |--------------------------------------------------------------------------
    | Scalability Projection
    |--------------------------------------------------------------------------
    |
    | Row count targets for scalability projections.
    |
    */

    'projection' => [
        'targets' => [1_000_000, 10_000_000],
    ],

    /*
    |--------------------------------------------------------------------------
    | Output Configuration
    |--------------------------------------------------------------------------
    |
    | Controls the default output format for the console command.
    |
    */

    'output' => [
        'format' => 'table',    // table, json
        'verbosity' => 'normal', // quiet, normal, verbose
    ],

    /*
    |--------------------------------------------------------------------------
    | CI Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for continuous integration pipeline usage.
    |
    */

    'ci' => [
        'fail_on_warning' => false,
        'fail_on_grade_below' => null, // e.g., 'C' to fail on D or F
    ],

    /*
    |--------------------------------------------------------------------------
    | Profiler Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for the profiler mode (closure/class method profiling).
    |
    */

    'profiler' => [

        // Number of identical normalized queries to trigger N+1 detection
        'n_plus_one_threshold' => 3,

        // Maximum number of queries to analyze individually (performance safety)
        'max_queries_to_analyze' => 100,

        // Whether to wrap profiled execution in a transaction + rollback
        'use_transaction' => true,

    ],

    /*
    |--------------------------------------------------------------------------
    | Diagnostics (Attribute-Based Profiling)
    |--------------------------------------------------------------------------
    |
    | Settings for #[QueryDiagnose] attribute-based automatic profiling.
    | This controls middleware interception for controllers and container
    | proxy interception for service classes.
    |
    */

    'diagnostics' => [

        // Master switch to enable/disable attribute-based profiling
        'enabled' => env('QUERY_SENTINEL_DIAGNOSTICS_ENABLED', true),

        // Global sample rate (0.0–1.0). Effective rate = min(method, global).
        'global_sample_rate' => (float) env('QUERY_SENTINEL_SAMPLE_RATE', 1.0),

        // Global default threshold in ms. Only log when cumulative time exceeds this.
        // Effective threshold = max(method, global).
        'default_threshold_ms' => (int) env('QUERY_SENTINEL_THRESHOLD_MS', 0),

        // Throw PerformanceViolationException on critical findings in CI
        'fail_on_critical_in_ci' => env('QUERY_SENTINEL_FAIL_ON_CRITICAL', false),

        // Service classes to wrap with MethodInterceptor via container proxy.
        // Any #[QueryDiagnose] methods on these classes will be auto-profiled.
        'classes' => [
            // \App\Services\MyService::class,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Query Scan (Interactive Builder Diagnosis)
    |--------------------------------------------------------------------------
    |
    | Settings for the query:scan command that discovers #[DiagnoseQuery]
    | annotated methods and allows interactive builder diagnosis.
    |
    */

    'scan' => [
        // Directories to scan for #[DiagnoseQuery] attributes (relative to base_path())
        'paths' => ['app', 'Modules'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cardinality Drift (Phase 1)
    |--------------------------------------------------------------------------
    */

    'cardinality_drift' => [
        'warning_threshold' => 0.5,
        'critical_threshold' => 0.9,
    ],

    /*
    |--------------------------------------------------------------------------
    | Anti-Pattern Detection (Phase 3)
    |--------------------------------------------------------------------------
    */

    'anti_patterns' => [
        'enabled' => true,
        'or_chain_threshold' => 3,
        'missing_limit_row_threshold' => 10000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Index Synthesis (Phase 4)
    |--------------------------------------------------------------------------
    */

    'index_synthesis' => [
        'max_recommendations' => 3,
        'max_columns_per_index' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Confidence Scoring (Phase 5)
    |--------------------------------------------------------------------------
    */

    'confidence' => [
        'minimum_for_findings' => 0.3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Concurrency Risk (Phase 6)
    |--------------------------------------------------------------------------
    */

    'concurrency' => [
        'enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Memory Pressure (Phase 7)
    |--------------------------------------------------------------------------
    */

    'memory_pressure' => [
        'high_threshold_bytes' => 268435456,     // 256MB
        'moderate_threshold_bytes' => 67108864,   // 64MB
        'concurrent_sessions' => 10,
        'network_transfer_threshold_bytes' => 52428800,  // 50MB
    ],

    /*
    |--------------------------------------------------------------------------
    | Hypothetical Index Simulation (Phase 10)
    |--------------------------------------------------------------------------
    */

    'hypothetical_index' => [
        'enabled' => false,
        'max_simulations' => 3,
        'timeout_seconds' => 5,
        'allowed_environments' => ['local', 'testing'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Workload-Level Modeling
    |--------------------------------------------------------------------------
    |
    | Tracks query patterns over time to detect repeated full exports,
    | API misuse bursts, and high-frequency large network transfers.
    |
    */

    'workload' => [
        'enabled' => true,
        'frequency_threshold' => 10,           // snapshots to consider "frequent"
        'export_row_threshold' => 100_000,     // rows to consider a "full export"
        'network_bytes_threshold' => 52428800, // 50MB
    ],

    /*
    |--------------------------------------------------------------------------
    | Regression Baselines (Phase 9)
    |--------------------------------------------------------------------------
    */

    'regression' => [
        'enabled' => true,
        'storage_path' => null,  // defaults to storage_path('query-sentinel/baselines')
        'max_history' => 10,
        'score_warning_threshold' => 10,
        'score_critical_threshold' => 25,
        'time_warning_threshold' => 50,
        'time_critical_threshold' => 200,
        'absolute_time_threshold' => 5,      // ms — require delta > 5ms in addition to percentage
        'absolute_score_threshold' => 5,     // points — require delta > 5pts in addition to percentage
        'noise_floor_ms' => 3,               // ignore time deltas below 3ms (timing jitter)
        'minimum_measurable_ms' => 5,        // suppress time regression warnings when baseline < 5ms
    ],

];
