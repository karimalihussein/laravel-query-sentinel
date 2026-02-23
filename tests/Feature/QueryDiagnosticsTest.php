<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Feature;

use QuerySentinel\Diagnostics\QueryDiagnostics;
use QuerySentinel\Support\Report;
use QuerySentinel\Tests\TestCase;

final class QueryDiagnosticsTest extends TestCase
{
    public function test_analyze_returns_report(): void
    {
        $diagnostics = $this->app->make(QueryDiagnostics::class);

        $report = $diagnostics->analyze('SELECT 1');

        $this->assertInstanceOf(Report::class, $report);
    }

    public function test_report_contains_original_sql(): void
    {
        $diagnostics = $this->app->make(QueryDiagnostics::class);

        $report = $diagnostics->analyze('SELECT * FROM users WHERE id = 1');

        $this->assertSame('SELECT * FROM users WHERE id = 1', $report->result->sql);
    }

    public function test_report_to_array_has_required_keys(): void
    {
        $diagnostics = $this->app->make(QueryDiagnostics::class);

        $report = $diagnostics->analyze('SELECT 1');
        $array = $report->toArray();

        $this->assertArrayHasKey('result', $array);
        $this->assertArrayHasKey('grade', $array);
        $this->assertArrayHasKey('passed', $array);
        $this->assertArrayHasKey('summary', $array);
        $this->assertArrayHasKey('composite_score', $array);
        $this->assertArrayHasKey('recommendations', $array);
        $this->assertArrayHasKey('analyzed_at', $array);
    }

    public function test_report_to_json_is_valid(): void
    {
        $diagnostics = $this->app->make(QueryDiagnostics::class);

        $report = $diagnostics->analyze('SELECT 1');
        $json = $report->toJson();

        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('grade', $decoded);
    }

    public function test_facade_resolves_to_diagnostics(): void
    {
        $report = \QuerySentinel\Facades\QueryDiagnostics::analyze('SELECT 1');

        $this->assertInstanceOf(Report::class, $report);
    }
}
