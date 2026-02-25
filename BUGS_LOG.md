# Bugs Log

## 2025-02-23: PHPStan CI Errors (12 errors)

### What the issue was
- `checkMissingIterableValueType` config option deprecated
- `BuilderAdapter::analyze()` / `Engine::analyzeBuilder()`: generic `Eloquent\Builder` missing `TModel` type
- `BuilderAdapter::interpolateBindings()`: `ConnectionInterface::getPdo()` undefined (interface has no getPdo)
- `ProfilerAdapter`: negated boolean expression always false
- `IndexCardinalityAnalyzer`: redundant `??` on array offsets that always exist; left side of `&&` always true
- `QueryAnalyzer`: redundant `??` on `grade` and `composite_score` (always present from scoring engine)
- `Finding::fromLegacy()`: offset `category` on left side of `??` does not exist in array type

### Why it happened
- PHPStan/Larastan updated; deprecated options and stricter generic/interface checks
- PHPDoc array shapes were more precise; PHPStan flagged redundant null coalescing and impossible conditions

### What was fixed
1. **phpstan.neon.dist**: Replaced `checkMissingIterableValueType: false` with `ignoreErrors: identifier: missingType.iterableValue`
2. **BuilderAdapter**: `@param EloquentBuilder<\Illuminate\Database\Eloquent\Model>|QueryBuilder`, `@var \Illuminate\Database\Connection`, and changed `interpolateBindings()` param type to `Connection`
3. **ProfilerAdapter**: Added `@phpstan-ignore-line` for the defensive `! $listening` check
4. **IndexCardinalityAnalyzer**: Removed `??` on `is_used`, `columns`, `table_rows`; used `$firstCol !== false` instead of `$firstCol &&`
5. **Engine**: `@param \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>|\Illuminate\Database\Query\Builder`
6. **QueryAnalyzer**: Removed `??` on `grade` and `composite_score`
7. **Finding**: Added `category?: string` to `$legacy` PHPDoc type

### Steps/commands taken
- `vendor/bin/phpstan analyse --no-progress`
- `vendor/bin/phpunit`

---

## 2025-02-23: CI Tests Failing – Connection Refused (14 errors, 3 failures)

### What the issue was
- In GitHub Actions, tests failed with `SQLSTATE[HY000] [2002] Connection refused (Connection: mysql, ...)`.
- Feature tests (MiddlewareTest, QueryCaptorTest, ContainerProxyTest, EngineTest, MethodInterceptorTest) run real DB calls (`DB::select()`, profiler, etc.). CI has no MySQL, so the default Laravel connection tried MySQL and failed.
- Three EngineTest profile tests failed because no queries were captured when the connection failed (0 totalQueries, empty duplicateQueries).

### Why it happened
- Testbench/Laravel default database connection in the test environment was effectively `mysql` (or env-driven) when no `.env` was present in CI.
- CI does not start a MySQL service; only PHP with `pdo_sqlite` is installed.

### What was fixed
- In `tests/TestCase.php`, implemented `getEnvironmentSetUp()` to force the test environment to use SQLite in-memory:
  - `config('database.default')` → `'sqlite'`
  - `config('database.connections.sqlite')` → `driver: 'sqlite', database: ':memory:'`
- All feature tests now run against SQLite, so no external MySQL is required in CI.

### Steps/commands taken
- Added `getEnvironmentSetUp()` in `tests/TestCase.php`.
- Ran `vendor/bin/phpunit` (263 tests, 719 assertions) – all pass.

---

## 2025-02-23: SQL Diagnostics Engine — Fail-Safe Validation Redesign

### What the issue was
- Engine produced full performance reports (grade, scores, recommendations) even when EXPLAIN failed or referenced non-existent tables.
- Example: `SELECT * FROM karimalihussein` (table doesn't exist) returned Grade A, 92.0 score, scalability, concurrency risk, etc., with "EXPLAIN failed" buried at the bottom.

### Why it happened
- QueryAnalyzer ran EXPLAIN first, then continued with parsing/scoring regardless of EXPLAIN success.
- MySqlDriver returned error strings instead of throwing.
- No pre-execution validation (syntax, schema, columns, joins).

### What was fixed
1. **ValidationPipeline** (Schema → Join → Syntax → EXPLAIN): validates tables, columns, joins, syntax before analysis.
2. **ExplainGuard**: wraps driver; throws `EngineAbortException` on any EXPLAIN failure; never returns error strings.
3. **EngineConsistencyValidator**: aborts if access_type=UNKNOWN or plan invalid before report.
4. **ValidationFailureReport** and **EngineAbortException**: structured failure output (no scoring, no scalability).
5. **TypoIntelligence**: Levenshtein suggestions for table/column typos (distance ≤ 2).
6. **ReportRenderer::renderValidationFailure()** and **DiagnoseQueryCommand** handle EngineAbortException.
7. **Config `validation.strict`**: when true (default), full fail-safe pipeline; when false (tests/SQLite), legacy behavior.
8. **SchemaValidator** supports MySQL, PostgreSQL, SQLite (INFORMATION_SCHEMA / pg_tables / sqlite_master / PRAGMA).

### Steps/commands taken
- Added `src/Validation/`, `ExplainGuard`, `EngineConsistencyValidator`, `ValidationFailureReport`, `TypoIntelligence`.
- Modified `QueryAnalyzer`, `Engine`, `DiagnoseQueryCommand`, `ReportRenderer`, `config/query-diagnostics.php`, `TestCase`.
- Fixed TypoIntelligence: `private const int` → `private const` for PHP 8.2 compatibility.

---

## 2026-02-24: ArchitecturalFixesTest Failures — BaselineStore Isolation and Missing Import

### What the issue was
- `test_rows_growth_with_degraded_per_row_performance_is_regression`: Failed asserting that null is not null — expected rows_examined regression, got none.
- `test_large_data_growth_logged_as_info_finding`: Failed asserting that an array is not empty — expected a Data growth info finding, got none.
- `test_intentional_scan_never_high_risk` / `test_pathological_scan_still_high_risk`: Class "QuerySentinel\Tests\Unit\ScalabilityEstimator" not found.

### Why it happened
- All three regression/baseline tests used `BaselineStore(':memory:')`, a shared directory in the project root. Data from previous test runs accumulated across the suite, so `history()` sometimes returned unrelated or polluted snapshots, breaking baseline comparison.
- ScalabilityEstimator was used in tests without a `use` statement, so PHP resolved it as `QuerySentinel\Tests\Unit\ScalabilityEstimator`.

### What was fixed
1. Replaced `BaselineStore(':memory:')` with `BaselineStore(sys_get_temp_dir() . '/qs_arch_' . uniqid('', true))` in the three affected tests so each run uses an isolated temp directory.
2. Added `use QuerySentinel\Analyzers\ScalabilityEstimator` to ArchitecturalFixesTest.php.

### Steps/commands taken
- Updated `test_rows_growth_with_stable_per_row_performance_is_data_growth`, `test_rows_growth_with_degraded_per_row_performance_is_regression`, and `test_large_data_growth_logged_as_info_finding` to use unique temp paths.
- Added the missing ScalabilityEstimator import.
- Ran `vendor/bin/phpunit` — 752 tests, 2080 assertions, OK.
