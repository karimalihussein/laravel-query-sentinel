<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Feature;

use QuerySentinel\Contracts\AnalyzerInterface;
use QuerySentinel\Core\Engine;
use QuerySentinel\Core\ProfileReport;
use QuerySentinel\Core\QueryAnalyzer;
use QuerySentinel\Diagnostics\QueryDiagnostics;
use QuerySentinel\Exceptions\UnsafeQueryException;
use QuerySentinel\Support\ExecutionGuard;
use QuerySentinel\Support\Report;
use QuerySentinel\Support\SqlSanitizer;
use QuerySentinel\Tests\TestCase;

final class EngineTest extends TestCase
{
    // ── Engine Resolution ──────────────────────────────────────────

    public function test_engine_is_resolvable(): void
    {
        $engine = $this->app->make(Engine::class);

        $this->assertInstanceOf(Engine::class, $engine);
    }

    public function test_query_analyzer_is_resolvable(): void
    {
        $analyzer = $this->app->make(QueryAnalyzer::class);

        $this->assertInstanceOf(QueryAnalyzer::class, $analyzer);
    }

    public function test_analyzer_interface_resolves_to_query_analyzer(): void
    {
        $analyzer = $this->app->make(AnalyzerInterface::class);

        $this->assertInstanceOf(QueryAnalyzer::class, $analyzer);
    }

    public function test_execution_guard_is_resolvable(): void
    {
        $guard = $this->app->make(ExecutionGuard::class);

        $this->assertInstanceOf(ExecutionGuard::class, $guard);
    }

    public function test_sql_sanitizer_is_resolvable(): void
    {
        $sanitizer = $this->app->make(SqlSanitizer::class);

        $this->assertInstanceOf(SqlSanitizer::class, $sanitizer);
    }

    // ── Backward Compatibility ─────────────────────────────────────

    public function test_legacy_diagnostics_still_resolvable(): void
    {
        $diagnostics = $this->app->make(QueryDiagnostics::class);

        $this->assertInstanceOf(QueryDiagnostics::class, $diagnostics);
    }

    public function test_legacy_diagnostics_still_works(): void
    {
        $diagnostics = $this->app->make(QueryDiagnostics::class);
        $report = $diagnostics->analyze('SELECT 1');

        $this->assertInstanceOf(Report::class, $report);
        $this->assertSame('SELECT 1', $report->result->sql);
    }

    public function test_legacy_facade_still_works(): void
    {
        $report = \QuerySentinel\Facades\QueryDiagnostics::analyze('SELECT 1');

        $this->assertInstanceOf(Report::class, $report);
    }

    public function test_new_facade_works(): void
    {
        $report = \QuerySentinel\Facades\QuerySentinel::analyze('SELECT 1');

        $this->assertInstanceOf(Report::class, $report);
    }

    // ── Mode 1: Raw SQL ────────────────────────────────────────────

    public function test_analyze_sql_returns_report(): void
    {
        $engine = $this->app->make(Engine::class);
        $report = $engine->analyzeSql('SELECT 1');

        $this->assertInstanceOf(Report::class, $report);
        $this->assertSame('sql', $report->mode);
    }

    public function test_analyze_sql_sanitizes_input(): void
    {
        $engine = $this->app->make(Engine::class);

        // Trailing semicolons and comments should be stripped
        $report = $engine->analyzeSql('SELECT 1; -- test comment');

        $this->assertInstanceOf(Report::class, $report);
        $this->assertSame('SELECT 1', $report->result->sql);
    }

    public function test_analyze_sql_blocks_destructive(): void
    {
        $engine = $this->app->make(Engine::class);

        $this->expectException(UnsafeQueryException::class);
        $engine->analyzeSql('DELETE FROM users');
    }

    public function test_analyze_sql_blocks_insert(): void
    {
        $engine = $this->app->make(Engine::class);

        $this->expectException(UnsafeQueryException::class);
        $engine->analyzeSql('INSERT INTO users (name) VALUES ("test")');
    }

    public function test_analyze_sql_blocks_update(): void
    {
        $engine = $this->app->make(Engine::class);

        $this->expectException(UnsafeQueryException::class);
        $engine->analyzeSql('UPDATE users SET name = "test"');
    }

    public function test_analyze_sql_blocks_drop(): void
    {
        $engine = $this->app->make(Engine::class);

        $this->expectException(UnsafeQueryException::class);
        $engine->analyzeSql('DROP TABLE users');
    }

    public function test_analyze_sql_blocks_empty_query(): void
    {
        $engine = $this->app->make(Engine::class);

        $this->expectException(UnsafeQueryException::class);
        $engine->analyzeSql('');
    }

    public function test_strict_validation_aborts_on_missing_table(): void
    {
        $this->app['config']->set('query-diagnostics.validation.strict', true);
        $engine = $this->app->make(Engine::class);

        $report = $engine->analyzeSql('SELECT * FROM karimalihussein WHERE id = 1');

        $this->assertTrue($report->isValidationFailure());
        $this->assertNotNull($report->validationFailure);
        $this->assertStringContainsString('Table', $report->validationFailure->status);
    }

    public function test_analyze_backward_compat_alias(): void
    {
        $engine = $this->app->make(Engine::class);

        // analyze() should behave identically to analyzeSql()
        $report = $engine->analyze('SELECT 1');

        $this->assertInstanceOf(Report::class, $report);
        $this->assertSame('sql', $report->mode);
    }

    // ── Report Mode Field ──────────────────────────────────────────

    public function test_report_mode_in_array_output(): void
    {
        $engine = $this->app->make(Engine::class);
        $report = $engine->analyzeSql('SELECT 1');

        $array = $report->toArray();
        $this->assertArrayHasKey('mode', $array);
        $this->assertSame('sql', $array['mode']);
    }

    public function test_report_mode_in_json_output(): void
    {
        $engine = $this->app->make(Engine::class);
        $report = $engine->analyzeSql('SELECT 1');

        $json = json_decode($report->toJson(), true);
        $this->assertSame('sql', $json['mode']);
    }

    // ── Mode 2: Builder ────────────────────────────────────────────

    public function test_analyze_builder_with_query_builder(): void
    {
        $engine = $this->app->make(Engine::class);
        $table = $this->getTestTableName();
        $builder = $this->app['db']->table($table)->select('*')->limit(1);

        $report = $engine->analyzeBuilder($builder);

        $this->assertInstanceOf(Report::class, $report);
        $this->assertSame('builder', $report->mode);
    }

    public function test_analyze_builder_invalid_input(): void
    {
        $engine = $this->app->make(Engine::class);

        $this->expectException(\InvalidArgumentException::class);
        $engine->analyzeBuilder(new \stdClass);
    }

    // ── Mode 3: Profiler ───────────────────────────────────────────

    public function test_profile_returns_profile_report(): void
    {
        $engine = $this->app->make(Engine::class);

        $report = $engine->profile(function () {
            // Execute some queries
            $this->app['db']->select('SELECT 1');
            $this->app['db']->select('SELECT 2');
        });

        $this->assertInstanceOf(ProfileReport::class, $report);
        $this->assertSame('profiler', $report->mode);
        $this->assertGreaterThanOrEqual(2, $report->totalQueries);
    }

    public function test_profile_detects_duplicate_queries(): void
    {
        $engine = $this->app->make(Engine::class);

        $report = $engine->profile(function () {
            for ($i = 0; $i < 5; $i++) {
                $this->app['db']->select('SELECT 1');
            }
        });

        $this->assertNotEmpty($report->duplicateQueries);
    }

    public function test_profile_handles_callback_exceptions(): void
    {
        $engine = $this->app->make(Engine::class);

        // Should not throw — exceptions are caught and queries still analyzed
        $report = $engine->profile(function () {
            $this->app['db']->select('SELECT 1');
            throw new \RuntimeException('Test exception');
        });

        $this->assertInstanceOf(ProfileReport::class, $report);
        $this->assertGreaterThanOrEqual(1, $report->totalQueries);
    }

    public function test_profile_report_to_array(): void
    {
        $engine = $this->app->make(Engine::class);

        $report = $engine->profile(function () {
            $this->app['db']->select('SELECT 1');
        });

        $array = $report->toArray();

        $this->assertArrayHasKey('mode', $array);
        $this->assertArrayHasKey('total_queries', $array);
        $this->assertArrayHasKey('cumulative_time_ms', $array);
        $this->assertArrayHasKey('n_plus_one_detected', $array);
        $this->assertArrayHasKey('duplicate_queries', $array);
        $this->assertArrayHasKey('individual_reports', $array);
    }

    public function test_profile_report_to_json(): void
    {
        $engine = $this->app->make(Engine::class);

        $report = $engine->profile(function () {
            $this->app['db']->select('SELECT 1');
        });

        $json = $report->toJson();
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertSame('profiler', $decoded['mode']);
    }

    // ── Engine Accessors ───────────────────────────────────────────

    public function test_engine_exposes_analyzer(): void
    {
        $engine = $this->app->make(Engine::class);

        $this->assertInstanceOf(AnalyzerInterface::class, $engine->getAnalyzer());
    }

    public function test_engine_exposes_guard(): void
    {
        $engine = $this->app->make(Engine::class);

        $this->assertInstanceOf(ExecutionGuard::class, $engine->getGuard());
    }

    public function test_engine_exposes_sanitizer(): void
    {
        $engine = $this->app->make(Engine::class);

        $this->assertInstanceOf(SqlSanitizer::class, $engine->getSanitizer());
    }
}
