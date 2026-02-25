# Laravel Query Sentinel

[![CI](https://github.com/karimalihussein/laravel-query-sentinel/actions/workflows/ci.yml/badge.svg)](https://github.com/karimalihussein/laravel-query-sentinel/actions/workflows/ci.yml)
[![Security](https://github.com/karimalihussein/laravel-query-sentinel/actions/workflows/security.yml/badge.svg)](https://github.com/karimalihussein/laravel-query-sentinel/actions/workflows/security.yml)

A driver-agnostic, CI-ready, extensible Laravel package for deep database query performance analysis. Provides EXPLAIN ANALYZE diagnostics, weighted composite scoring, complexity classification, N+1 detection, scalability projections, and attribute-based automatic profiling for production applications.

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Configuration](#configuration)
- [Analysis Modes](#analysis-modes)
  - [Mode 1: Raw SQL Analysis](#mode-1-raw-sql-analysis)
  - [Mode 2: Query Builder / Eloquent Analysis](#mode-2-query-builder--eloquent-analysis)
  - [Mode 3: Closure Profiling](#mode-3-closure-profiling)
  - [Mode 4: Class Method Profiling](#mode-4-class-method-profiling)
- [Automatic Profiling with Attributes](#automatic-profiling-with-attributes)
  - [Controller Profiling (Middleware)](#controller-profiling-middleware)
  - [Service Class Profiling (Container Proxy)](#service-class-profiling-container-proxy)
  - [Sampling and Thresholds](#sampling-and-thresholds)
  - [Fail on Critical](#fail-on-critical)
  - [Structured Logging](#structured-logging)
- [Console Command](#console-command)
- [Report Reference](#report-reference)
  - [Report Object (Single Query)](#report-object-single-query)
  - [ProfileReport Object (Multiple Queries)](#profilereport-object-multiple-queries)
  - [Grading System](#grading-system)
  - [Scoring Components](#scoring-components)
  - [Metrics Extracted](#metrics-extracted)
- [Built-in Rules](#built-in-rules)
- [Custom Rules](#custom-rules)
- [Extension Points](#extension-points)
  - [Custom Drivers](#custom-drivers)
  - [Custom Scoring Engine](#custom-scoring-engine)
- [Architecture](#architecture)
- [Testing](#testing)
- [License](#license)

---

## Requirements

- PHP 8.2+
- Laravel 10, 11, or 12
- MySQL 8.0.18+ (for EXPLAIN ANALYZE) or PostgreSQL

## Installation

> **Development only** — Install this package as a dev dependency. It will not be installed in production when you run `composer install --no-dev`, keeping your production environment lean and avoiding any performance overhead from diagnostics.

```bash
composer require --dev karimalihussein/laravel-query-sentinel
```

The service provider is auto-discovered. To publish the configuration:

```bash
php artisan vendor:publish --tag=query-sentinel-config
```

- **Production:** When deploying with `composer install --no-dev`, this package is not installed. Your application runs without Query Sentinel—no code changes or conditional checks are needed.
- **Local/CI:** Run `composer install` (with dev dependencies) to use Query Sentinel for development and CI pipelines.

Two facades are registered automatically:

- `QuerySentinel` (primary)
- `QueryDiagnostics` (backward-compatible alias)

## Quick Start

```php
use QuerySentinel\Facades\QuerySentinel;

// Analyze a raw SQL query
$report = QuerySentinel::analyzeSql('SELECT * FROM users WHERE email = ?');

echo $report->grade;          // 'A'
echo $report->compositeScore; // 92.5
echo $report->passed;         // true
echo $report->summary;        // 'Query uses index lookup with good selectivity...'

// Analyze an Eloquent builder (without executing it)
$builder = User::where('status', 'active')->select('id', 'name');
$report = QuerySentinel::analyzeBuilder($builder);

// Profile all queries in a closure
$profile = QuerySentinel::profile(function () {
    $users = User::with('posts', 'comments')->paginate(15);
});

echo $profile->totalQueries;      // 3
echo $profile->nPlusOneDetected;  // false
echo $profile->worstGrade();      // 'B'
```

---

## Configuration

After publishing, edit `config/query-diagnostics.php`:

```php
return [

    // Database driver: 'mysql' or 'pgsql'
    'driver' => env('QUERY_SENTINEL_DRIVER', 'mysql'),

    // Database connection name (null = default)
    'connection' => env('QUERY_SENTINEL_CONNECTION'),

    // Scoring weights (must sum to 1.0)
    'scoring' => [
        'weights' => [
            'execution_time'  => 0.30,
            'scan_efficiency' => 0.25,
            'index_quality'   => 0.20,
            'join_efficiency' => 0.15,
            'scalability'     => 0.10,
        ],
        'grade_thresholds' => [
            'A' => 90, 'B' => 75, 'C' => 50, 'D' => 25, 'F' => 0,
        ],
    ],

    // Rules to enable for analysis
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

    // Performance thresholds used by rules
    'thresholds' => [
        'max_execution_time_ms' => 1000,
        'max_rows_examined'     => 100_000,
        'max_loops'             => 10_000,
        'max_cost'              => 1_000_000,
        'max_nested_loop_depth' => 4,
    ],

    // Scalability projection targets
    'projection' => [
        'targets' => [1_000_000, 10_000_000],
    ],

    // Console command output defaults
    'output' => [
        'format'    => 'table',   // 'table' or 'json'
        'verbosity' => 'normal',  // 'quiet', 'normal', 'verbose'
    ],

    // CI pipeline settings
    'ci' => [
        'fail_on_warning'     => false,
        'fail_on_grade_below' => null,  // e.g., 'C' to fail on D or F
    ],

    // Profiler settings (closure/class method profiling)
    'profiler' => [
        'n_plus_one_threshold'   => 3,     // repeated queries to flag N+1
        'max_queries_to_analyze' => 100,   // performance safety cap
        'use_transaction'        => true,  // wrap in transaction + rollback
    ],

    // Attribute-based automatic profiling (#[QueryDiagnose])
    'diagnostics' => [
        'enabled'                => env('QUERY_SENTINEL_DIAGNOSTICS_ENABLED', true),
        'global_sample_rate'     => (float) env('QUERY_SENTINEL_SAMPLE_RATE', 1.0),
        'default_threshold_ms'   => (int) env('QUERY_SENTINEL_THRESHOLD_MS', 0),
        'fail_on_critical_in_ci' => env('QUERY_SENTINEL_FAIL_ON_CRITICAL', false),
        'classes' => [
            // Service classes to auto-profile via container proxy:
            // \App\Services\LeadQueryService::class,
        ],
    ],

];
```

### Environment Variables

| Variable                             | Default | Description                           |
| ------------------------------------ | ------- | ------------------------------------- |
| `QUERY_SENTINEL_DRIVER`              | `mysql` | Database driver (`mysql` or `pgsql`)  |
| `QUERY_SENTINEL_CONNECTION`          | `null`  | Specific database connection name     |
| `QUERY_SENTINEL_DIAGNOSTICS_ENABLED` | `true`  | Enable attribute-based profiling      |
| `QUERY_SENTINEL_SAMPLE_RATE`         | `1.0`   | Global sample rate (0.0 - 1.0)        |
| `QUERY_SENTINEL_THRESHOLD_MS`        | `0`     | Global minimum cumulative time to log |
| `QUERY_SENTINEL_FAIL_ON_CRITICAL`    | `false` | Throw exception on critical findings  |

---

## Analysis Modes

Query Sentinel provides four distinct modes of analysis, each suited to different use cases.

### Mode 1: Raw SQL Analysis

Analyze a raw SQL string directly. The query is validated for safety (only SELECT/WITH allowed), sanitized, and run through EXPLAIN ANALYZE.

```php
use QuerySentinel\Facades\QuerySentinel;

$report = QuerySentinel::analyzeSql(
    "SELECT u.*, COUNT(p.id) as post_count
     FROM users u
     LEFT JOIN posts p ON p.user_id = u.id
     WHERE u.status = 'active'
     GROUP BY u.id
     ORDER BY post_count DESC
     LIMIT 20"
);

// Inspect the results
echo $report->grade;            // 'B'
echo $report->compositeScore;   // 78.4
echo $report->passed;           // true (no critical findings)
echo $report->summary;

// Detailed metrics
$metrics = $report->result->metrics;
echo $metrics['rows_examined'];      // 15000
echo $metrics['complexity_label'];   // 'Sort on full result set'
echo $metrics['has_filesort'];       // true

// Actionable recommendations
foreach ($report->recommendations as $rec) {
    echo "- {$rec}\n";
}

// Scalability projections
$scalability = $report->scalability;
echo $scalability['risk'];  // 'MEDIUM'
foreach ($scalability['projections'] as $proj) {
    echo "{$proj['label']}: {$proj['projected_time_ms']}ms\n";
}

// Serialize
$json = $report->toJson(JSON_PRETTY_PRINT);
$array = $report->toArray();
```

**Safety:** Only read-only SQL is accepted. INSERT, UPDATE, DELETE, DROP, ALTER, TRUNCATE, and other destructive statements are blocked and will throw `UnsafeQueryException`.

### Mode 2: Query Builder / Eloquent Analysis

Analyze an Eloquent or Query Builder instance **without executing it**. Query Sentinel extracts the SQL and bindings from the builder, interpolates them safely, and runs the analysis.

```php
use QuerySentinel\Facades\QuerySentinel;
use App\Models\User;

// Eloquent Builder
$builder = User::query()
    ->where('status', 'active')
    ->where('created_at', '>=', now()->subDays(30))
    ->select('id', 'name', 'email');

$report = QuerySentinel::analyzeBuilder($builder);
echo $report->grade;  // 'A'

// Query Builder
$builder = DB::table('orders')
    ->join('users', 'users.id', '=', 'orders.user_id')
    ->where('orders.total', '>', 100)
    ->select('orders.*', 'users.name');

$report = QuerySentinel::analyzeBuilder($builder);
```

**The builder is never executed.** Only `toSql()` and `getBindings()` are called.

### Mode 3: Closure Profiling

Profile all database queries executed inside a closure. Captures every query via `DB::listen()`, wraps execution in a transaction (rolled back after), analyzes each SELECT individually, and returns an aggregated `ProfileReport`.

```php
use QuerySentinel\Facades\QuerySentinel;

$profile = QuerySentinel::profile(function () {
    $users = User::with(['posts', 'comments'])->where('active', true)->get();

    foreach ($users as $user) {
        $user->updateQuietly(['last_seen' => now()]);
    }

    return $users->count();
});

// Aggregate statistics
echo $profile->totalQueries;       // 12
echo $profile->analyzedQueries;    // 3 (only SELECTs are analyzed)
echo $profile->cumulativeTimeMs;   // 45.23
echo $profile->nPlusOneDetected;   // false

// Worst performing query
echo $profile->worstQuery->grade;           // 'C'
echo $profile->worstQuery->compositeScore;  // 55.0
echo $profile->worstQuery->result->sql;

// Slowest query by wall clock time
echo $profile->slowestQuery->result->executionTimeMs;  // 18.5

// Duplicate query detection
foreach ($profile->duplicateQueries as $sql => $count) {
    echo "Duplicate ({$count}x): {$sql}\n";
}

// Overall worst grade
echo $profile->worstGrade();          // 'C'
echo $profile->hasCriticalFindings(); // false
```

**Safety:** The closure is wrapped in a database transaction that is rolled back after execution. No writes persist. If the closure throws an exception, the transaction is still rolled back and captured queries are still analyzed.

### Mode 4: Class Method Profiling

Profile a specific class method by resolving it from the Laravel container. Combines dependency injection with closure profiling.

```php
use QuerySentinel\Facades\QuerySentinel;

$profile = QuerySentinel::profileClass(
    \App\Services\LeadQueryService::class,
    'getFilteredLeads',
    [$filterDTO, $page = 1, $perPage = 15],
);

echo $profile->totalQueries;
echo $profile->worstGrade();
```

The class is resolved via `app()`, so all constructor dependencies are injected automatically.

---

## Automatic Profiling with Attributes

The `#[QueryDiagnose]` PHP 8 attribute enables **zero-code-change profiling**. Place it on any controller or service method to automatically capture, analyze, and log query performance.

### Controller Profiling (Middleware)

**Step 1:** Register the middleware in your HTTP kernel or route group:

```php
// app/Http/Kernel.php (Laravel 10)
protected $routeMiddleware = [
    // ...
    'query.diagnose' => \QuerySentinel\Interception\QueryDiagnoseMiddleware::class,
];
```

**Step 2:** Apply to routes:

```php
// routes/api.php
Route::middleware(['auth:sanctum', 'query.diagnose'])->group(function () {
    Route::get('/leads', [LeadsController::class, 'index']);
    Route::get('/leads/search', [LeadsController::class, 'search']);
});
```

**Step 3:** Add the attribute to controller methods:

```php
use QuerySentinel\Attributes\QueryDiagnose;

class LeadsController extends Controller
{
    #[QueryDiagnose]
    public function index(LeadFilterDTO $dto)
    {
        // All queries here are automatically captured and analyzed.
        // No code changes needed inside the method.
        return LeadResource::collection(
            $this->service->getFilteredLeads($dto)
        );
    }

    #[QueryDiagnose(thresholdMs: 100, sampleRate: 0.25)]
    public function search(Request $request)
    {
        // Profiled 25% of the time, logged only if queries take > 100ms
        return $this->service->search($request->input('q'));
    }
}
```

Methods **without** the attribute pass through with zero overhead. The middleware only activates for attributed methods.

### Service Class Profiling (Container Proxy)

For non-controller classes, register them in the config:

```php
// config/query-diagnostics.php
'diagnostics' => [
    'classes' => [
        \App\Services\LeadQueryService::class,
        \App\Services\ReportService::class,
    ],
],
```

Then add the attribute to methods:

```php
use QuerySentinel\Attributes\QueryDiagnose;

class LeadQueryService
{
    #[QueryDiagnose(thresholdMs: 50)]
    public function getFilteredLeads(LeadFilterDTO $dto): LengthAwarePaginator
    {
        return Client::query()
            ->with(['submissions', 'branch'])
            ->filter($dto)
            ->paginate($dto->perPage);
    }

    // This method has no attribute — it passes through untouched
    public function getById(int $id): Client
    {
        return Client::findOrFail($id);
    }
}
```

When the service is resolved from the container, it is automatically wrapped in a `MethodInterceptor` proxy. The proxy intercepts calls to attributed methods, profiles them, and forwards everything else directly. Property access (`__get`, `__set`, `__isset`) is forwarded transparently.

You can also register proxies programmatically:

```php
use QuerySentinel\Interception\ContainerProxy;

// In a service provider's boot() method:
ContainerProxy::register($this->app, [
    \App\Services\LeadQueryService::class,
]);
```

### Sampling and Thresholds

Control profiling frequency and logging noise in production.

**Sampling** determines if a request is profiled at all:

```php
#[QueryDiagnose(sampleRate: 0.05)]  // Profile 5% of invocations
```

The effective rate is `min(methodRate, globalRate)`. Configure the global rate via environment:

```env
QUERY_SENTINEL_SAMPLE_RATE=0.10   # Global cap: profile max 10% of requests
```

**Thresholds** filter noise after profiling:

```php
#[QueryDiagnose(thresholdMs: 200)]  // Only log if cumulative query time >= 200ms
```

The effective threshold is `max(methodThreshold, globalDefault)`. Configure the global default:

```env
QUERY_SENTINEL_THRESHOLD_MS=100   # Never log invocations under 100ms
```

| Attribute Param | Config Key                         | Combination Logic                             |
| --------------- | ---------------------------------- | --------------------------------------------- |
| `sampleRate`    | `diagnostics.global_sample_rate`   | `min(method, global)` — most restrictive wins |
| `thresholdMs`   | `diagnostics.default_threshold_ms` | `max(method, global)` — highest bar wins      |

### Fail on Critical

Throw a `PerformanceViolationException` when critical performance issues are detected. Useful for CI/testing environments.

```php
#[QueryDiagnose(failOnCritical: true)]
public function criticalEndpoint()
{
    // If queries get grade D/F, slow query >500ms, N+1, or full table scan:
    // → PerformanceViolationException is thrown
}
```

The exception includes the full `ProfileReport` for inspection:

```php
try {
    $service->criticalEndpoint();
} catch (PerformanceViolationException $e) {
    echo $e->getMessage();
    // "QuerySentinel performance violation in LeadQueryService::criticalEndpoint
    //  — grade F detected, slow query 750ms, N+1 query pattern"

    $e->report;  // ProfileReport
    $e->class;   // 'App\Services\LeadQueryService'
    $e->method;  // 'criticalEndpoint'
}
```

Conditions that trigger the exception:

- Worst query grade is D or F
- Any individual query exceeds 500ms
- Full table scan detected
- N+1 query pattern detected

### Structured Logging

Every profiled invocation that passes sampling and threshold checks is logged as structured JSON to a configurable Laravel log channel.

```php
#[QueryDiagnose(logChannel: 'performance')]
```

Log output:

```json
{
  "type": "query_sentinel_profile",
  "class": "App\\Services\\LeadQueryService",
  "method": "getFilteredLeads",
  "total_queries": 5,
  "analyzed_queries": 3,
  "cumulative_time_ms": 45.23,
  "slowest_query_ms": 18.5,
  "rows_examined": 15000,
  "grade": "B",
  "n_plus_one": false,
  "duplicate_queries": 0,
  "warnings": ["Missing index on status column"],
  "memory_mb": 12.5,
  "analyzed_at": "2026-02-23T14:30:00+00:00"
}
```

Log levels are determined automatically:

- **error** — Grade D or F
- **warning** — Grade C or N+1 detected
- **info** — Grade A or B

Configure your log channel in `config/logging.php`:

```php
'channels' => [
    'performance' => [
        'driver' => 'daily',
        'path'   => storage_path('logs/performance.log'),
        'level'  => 'info',
        'days'   => 14,
    ],
],
```

---

## Console Command

Analyze queries from the command line:

```bash
# Basic analysis with console report
php artisan query:diagnose "SELECT * FROM users WHERE email = 'test@example.com'"

# JSON output (CI-friendly)
php artisan query:diagnose "SELECT * FROM users WHERE id = 1" --json

# Fail with non-zero exit code if warnings found
php artisan query:diagnose "SELECT * FROM users" --fail-on-warning

# Use a specific database connection
php artisan query:diagnose "SELECT * FROM users" --connection=reporting
```

### Console Report Output

```
=========================================================
  QUERY SENTINEL - Performance Diagnostic Report
=========================================================

  Status:     PASS — No issues
  Grade:      A (92.5 / 100)
  Time:       1.23ms
  Findings:   0 critical  0 warnings  1 optimizations  1 info
  Driver:     mysql

  Execution Plan Analysis:
  ----------------------------------------------------------------------
  Rows Examined:       150
  Rows Returned:       15
  Selectivity:         10.0x
  Nested Loop Depth:   1
  Max Loops:           1
  Complexity:          LIMIT-optimized (early termination)
  Flags:               COVERING_IDX, EARLY_TERM
  Indexes:             idx_users_email
  ----------------------------------------------------------------------

  Performance Score Breakdown:
  ----------------------------------------------------------------------
    execution_time     95/100  [|||||||||||||||||||.]  (30% weight)
    scan_efficiency    90/100  [||||||||||||||||||..]  (25% weight)
    index_quality      95/100  [|||||||||||||||||||.]  (20% weight)
    join_efficiency   100/100  [||||||||||||||||||||]  (15% weight)
    scalability        85/100  [|||||||||||||||||...]  (10% weight)
  ----------------------------------------------------------------------

  Scalability Projection:
  ----------------------------------------------------------------------
  Current Rows:        10,000
  Risk:                LOW
    at 1M:  GOOD  (projected 12.3ms)
    at 10M: MODERATE  (projected 123.0ms)
  ----------------------------------------------------------------------

  Actionable Recommendations:
  ----------------------------------------------------------------------
  1. Consider adding a covering index for the selected columns
  ----------------------------------------------------------------------

  PASS: No critical performance issues.
```

### CI Integration

```yaml
# .github/workflows/query-check.yml
- name: Check query performance
  run: |
    php artisan query:diagnose "SELECT * FROM leads WHERE status = 'active'" \
      --fail-on-warning --json
```

The command exits with code `1` when `--fail-on-warning` is set and the query has critical findings.

---

## Report Reference

### Report Object (Single Query)

Returned by `analyzeSql()` and `analyzeBuilder()`:

```php
$report->result;           // Result — raw analysis data
$report->grade;            // string — 'A', 'B', 'C', 'D', or 'F'
$report->passed;           // bool — true if no critical findings
$report->summary;          // string — human-readable summary
$report->recommendations;  // string[] — actionable suggestions
$report->compositeScore;   // float — 0.0 to 100.0
$report->scalability;      // array — growth projections
$report->mode;             // string — 'sql', 'builder', or 'profiler'
$report->analyzedAt;       // DateTimeImmutable

// Methods
$report->toArray();                   // array serialization
$report->toJson(JSON_PRETTY_PRINT);   // JSON serialization
$report->findingCounts();             // ['critical' => 0, 'warning' => 1, ...]
```

### ProfileReport Object (Multiple Queries)

Returned by `profile()`, `profileClass()`, and the automatic profiling system:

```php
$profile->totalQueries;       // int — all captured queries
$profile->analyzedQueries;    // int — SELECT queries analyzed via EXPLAIN
$profile->cumulativeTimeMs;   // float — total execution time
$profile->slowestQuery;       // ?Report — highest execution time
$profile->worstQuery;         // ?Report — lowest composite score
$profile->duplicateQueries;   // array — normalized SQL => count (only duplicates)
$profile->nPlusOneDetected;   // bool — any query repeated >= threshold
$profile->individualReports;  // Report[] — per-query analysis
$profile->queryCounts;        // array — all normalized SQL => count
$profile->skippedQueries;     // string[] — non-SELECT queries
$profile->captures;           // QueryCapture[] — raw captured queries
$profile->analyzedAt;         // DateTimeImmutable

// Methods
$profile->worstGrade();          // string — worst grade across all queries
$profile->hasCriticalFindings(); // bool
$profile->toArray();
$profile->toJson(JSON_PRETTY_PRINT);
```

### Grading System

Grades are derived from the weighted composite score:

| Grade | Score Range | Meaning                                 |
| ----- | ----------- | --------------------------------------- |
| **A** | 90 - 100    | Excellent — well-optimized query        |
| **B** | 75 - 89     | Good — minor optimization opportunities |
| **C** | 50 - 74     | Fair — notable performance issues       |
| **D** | 25 - 49     | Poor — significant performance problems |
| **F** | 0 - 24      | Critical — severe performance issues    |

A **context override** promotes queries to grade A (score clamped to 95+) when all of these conditions are met:

- LIMIT-optimized (early termination)
- Uses covering index
- No filesort
- Execution time < 10ms

### Scoring Components

Five weighted components combine into the composite score:

| Component         | Default Weight | What It Measures                           |
| ----------------- | -------------- | ------------------------------------------ |
| `execution_time`  | 30%            | Query execution speed                      |
| `scan_efficiency` | 25%            | Ratio of rows returned vs rows examined    |
| `index_quality`   | 20%            | Index usage and covering index detection   |
| `join_efficiency` | 15%            | Join type quality and loop counts          |
| `scalability`     | 10%            | Projected performance at higher row counts |

Customize weights in configuration (must sum to 1.0):

```php
'scoring' => [
    'weights' => [
        'execution_time'  => 0.40,  // Prioritize speed
        'scan_efficiency' => 0.20,
        'index_quality'   => 0.20,
        'join_efficiency' => 0.10,
        'scalability'     => 0.10,
    ],
],
```

### Metrics Extracted

The `$report->result->metrics` array contains:

| Metric                  | Type     | Description                                                        |
| ----------------------- | -------- | ------------------------------------------------------------------ |
| `execution_time_ms`     | float    | Query execution time in milliseconds                               |
| `rows_examined`         | int      | Total rows read from storage engine                                |
| `rows_returned`         | int      | Rows returned to client                                            |
| `selectivity_ratio`     | float    | rows_returned / rows_examined                                      |
| `nested_loop_depth`     | int      | Number of join nesting levels                                      |
| `max_loops`             | int      | Maximum loop iteration count                                       |
| `complexity`            | string   | Enum value: `O(limit)`, `O(range)`, `O(n)`, `O(n log n)`, `O(n^2)` |
| `complexity_label`      | string   | Human-readable label                                               |
| `has_table_scan`        | bool     | Full table scan detected                                           |
| `has_filesort`          | bool     | External sort operation used                                       |
| `has_temp_table`        | bool     | Temporary table created                                            |
| `has_disk_temp`         | bool     | Temporary table written to disk                                    |
| `has_weedout`           | bool     | Semi-join weedout optimization                                     |
| `has_index_merge`       | bool     | Index merge optimization used                                      |
| `has_covering_index`    | bool     | Query served entirely from index                                   |
| `has_early_termination` | bool     | LIMIT-optimized early termination                                  |
| `is_index_backed`       | bool     | Query uses any index                                               |
| `indexes_used`          | string[] | Names of indexes used                                              |
| `tables_accessed`       | string[] | Names of tables accessed                                           |

---

## Built-in Rules

Query Sentinel ships with 9 performance rules that evaluate extracted metrics:

| Rule                      | Severity         | Triggers When                                   |
| ------------------------- | ---------------- | ----------------------------------------------- |
| `FullTableScanRule`       | critical         | Full table scan on > 10,000 rows                |
| `NoIndexRule`             | critical         | No index used at all                            |
| `TempTableRule`           | critical/warning | Temporary table created (critical if on disk)   |
| `QuadraticComplexityRule` | critical         | O(n^2) complexity detected                      |
| `DeepNestedLoopRule`      | warning          | Nested loop depth exceeds threshold (default 4) |
| `StaleStatsRule`          | warning          | Table statistics appear outdated                |
| `LimitIneffectiveRule`    | warning          | LIMIT clause doesn't prevent full scan          |
| `IndexMergeRule`          | info             | Index merge optimization detected               |
| `WeedoutRule`             | info             | Semi-join weedout strategy detected             |

Disable or reorder rules in configuration:

```php
'rules' => [
    'enabled' => [
        // Only enable rules you care about:
        \QuerySentinel\Rules\FullTableScanRule::class,
        \QuerySentinel\Rules\NoIndexRule::class,
        \QuerySentinel\Rules\DeepNestedLoopRule::class,
    ],
],
```

---

## Custom Rules

Create custom rules by extending `BaseRule`:

```php
<?php

namespace App\QueryRules;

use QuerySentinel\Rules\BaseRule;

class SlowQueryRule extends BaseRule
{
    public function evaluate(array $metrics): ?array
    {
        $executionTime = $metrics['execution_time_ms'] ?? 0;

        if ($executionTime > 500) {
            return $this->finding(
                severity: 'critical',
                title: 'Slow query detected',
                description: sprintf('Query took %.0fms, exceeding the 500ms threshold.', $executionTime),
                recommendation: 'Optimize the query or add appropriate indexes.',
            );
        }

        if ($executionTime > 100) {
            return $this->finding(
                severity: 'warning',
                title: 'Query approaching slow threshold',
                description: sprintf('Query took %.0fms.', $executionTime),
                recommendation: 'Monitor this query for further degradation.',
            );
        }

        return null;
    }

    public function key(): string
    {
        return 'slow_query';
    }

    public function name(): string
    {
        return 'Slow Query Detection';
    }
}
```

Register it in `config/query-diagnostics.php`:

```php
'rules' => [
    'enabled' => [
        // Built-in rules...
        \QuerySentinel\Rules\FullTableScanRule::class,
        // ...

        // Your custom rules:
        \App\QueryRules\SlowQueryRule::class,
    ],
],
```

The `finding()` helper produces a standardized array with keys: `severity`, `category`, `title`, `description`, `recommendation`, `metadata`.

Available severities: `info`, `optimization`, `warning`, `critical`.

---

## Extension Points

### Custom Drivers

Implement `DriverInterface` for databases beyond MySQL/PostgreSQL:

```php
<?php

namespace App\Drivers;

use QuerySentinel\Contracts\DriverInterface;

class SqliteDriver implements DriverInterface
{
    public function runExplain(string $sql): array
    {
        return DB::select('EXPLAIN QUERY PLAN ' . $sql);
    }

    public function runExplainAnalyze(string $sql): string
    {
        // SQLite doesn't support EXPLAIN ANALYZE natively
        return json_encode(DB::select('EXPLAIN QUERY PLAN ' . $sql));
    }

    public function getName(): string
    {
        return 'sqlite';
    }

    public function supportsAnalyze(): bool
    {
        return false;
    }
}
```

Register in a service provider:

```php
use QuerySentinel\Contracts\DriverInterface;

$this->app->singleton(DriverInterface::class, SqliteDriver::class);
```

### Custom Scoring Engine

Implement `ScoringEngineInterface` for a different scoring algorithm:

```php
use QuerySentinel\Contracts\ScoringEngineInterface;

$this->app->singleton(ScoringEngineInterface::class, MyCustomScoringEngine::class);
```

---

## Architecture

```
src/
├── Adapters/                  # Framework-specific input adapters
│   ├── BuilderAdapter.php     #   Eloquent/Query Builder → SQL
│   ├── ClassMethodAdapter.php #   Class method → closure profiling
│   ├── ProfilerAdapter.php    #   Closure → captured queries
│   └── SqlAdapter.php         #   Raw SQL → validated SQL
│
├── Analyzers/                 # Metric extraction and estimation
│   ├── MetricsExtractor.php   #   EXPLAIN plan → structured metrics
│   └── ScalabilityEstimator.php # Growth projections
│
├── Attributes/                # PHP 8 Attributes
│   └── QueryDiagnose.php      #   Method-level profiling attribute
│
├── Console/                   # Artisan commands
│   └── DiagnoseQueryCommand.php
│
├── Contracts/                 # Interfaces (extension points)
│   ├── AnalyzerInterface.php
│   ├── AdapterInterface.php
│   ├── DriverInterface.php
│   ├── PlanParserInterface.php
│   ├── ProfilerInterface.php
│   ├── RuleInterface.php
│   ├── RuleRegistryInterface.php
│   └── ScoringEngineInterface.php
│
├── Core/                      # Framework-agnostic engine
│   ├── Engine.php             #   Unified entry point (all 4 modes)
│   ├── ProfileReport.php      #   Aggregated multi-query report
│   └── QueryAnalyzer.php      #   Core analysis pipeline
│
├── Diagnostics/               # Legacy compatibility
│   └── QueryDiagnostics.php   #   Delegates to Core\QueryAnalyzer
│
├── Drivers/                   # Database-specific EXPLAIN implementations
│   ├── MySqlDriver.php
│   └── PostgresDriver.php
│
├── Enums/                     # Value enumerations
│   ├── ComplexityClass.php    #   O(limit), O(range), O(n), O(n log n), O(n^2)
│   └── Severity.php           #   info, optimization, warning, critical
│
├── Exceptions/                # Custom exceptions
│   ├── PerformanceViolationException.php  # Fail-on-critical
│   └── UnsafeQueryException.php           # Destructive SQL blocked
│
├── Facades/                   # Laravel facades
│   ├── QueryDiagnostics.php   #   Backward-compatible alias
│   └── QuerySentinel.php      #   Primary facade
│
├── Interception/              # Attribute-based automatic profiling
│   ├── ContainerProxy.php     #   app->extend() for service classes
│   ├── MethodInterceptor.php  #   __call() proxy for attributed methods
│   ├── QueryCaptor.php        #   Passive DB::listen capture
│   └── QueryDiagnoseMiddleware.php  # HTTP middleware for controllers
│
├── Logging/                   # Structured logging
│   └── ReportLogger.php       #   JSON logging to Laravel channels
│
├── Parsers/                   # EXPLAIN output parsing
│   └── ExplainPlanParser.php
│
├── Rules/                     # Performance rules (9 built-in)
│   ├── BaseRule.php           #   Abstract base with finding() helper
│   ├── DeepNestedLoopRule.php
│   ├── FullTableScanRule.php
│   ├── IndexMergeRule.php
│   ├── LimitIneffectiveRule.php
│   ├── NoIndexRule.php
│   ├── QuadraticComplexityRule.php
│   ├── RuleRegistry.php
│   ├── StaleStatsRule.php
│   ├── TempTableRule.php
│   └── WeedoutRule.php
│
├── Scoring/                   # Composite scoring
│   └── DefaultScoringEngine.php
│
├── Support/                   # Framework-agnostic utilities
│   ├── ExecutionGuard.php     #   SQL safety validation
│   ├── PlanNode.php           #   Execution plan tree node
│   ├── QueryCapture.php       #   Captured query DTO
│   ├── Report.php             #   Single-query report
│   ├── Result.php             #   Raw analysis result
│   ├── SamplingGuard.php      #   Probabilistic sampling
│   ├── SqlSanitizer.php       #   SQL comment/whitespace cleanup
│   └── ThresholdGuard.php     #   Cumulative time filtering
│
└── QueryDiagnosticsServiceProvider.php  # Service provider
```

### Design Principles

- **Framework-agnostic core:** `Core\QueryAnalyzer` operates only on SQL strings and has no Laravel dependency. All Laravel integration lives in adapters, interception, and the service provider.
- **Lazy adapter loading:** Adapter classes (BuilderAdapter, ProfilerAdapter) are only instantiated when their corresponding Engine methods are called.
- **Safety first:** `ExecutionGuard` blocks all destructive SQL. `ProfilerAdapter` wraps closures in transaction+rollback. `QueryCaptor` captures passively without transaction wrapping (production-safe).
- **Zero overhead for non-attributed methods:** The middleware and interceptor skip methods without `#[QueryDiagnose]` with only a cached reflection lookup.

---

## Testing

```bash
cd libs/laravel-query-sentinel

# Install dependencies
composer install

# Run all tests (229 tests, 627 assertions)
vendor/bin/phpunit

# Run specific test suite
vendor/bin/phpunit --testsuite=Unit
vendor/bin/phpunit --testsuite=Feature

# Run a specific test
vendor/bin/phpunit --filter=SamplingGuardTest

# Code style
vendor/bin/pint

# Static analysis
vendor/bin/phpstan analyse
```

---

## License

MIT
