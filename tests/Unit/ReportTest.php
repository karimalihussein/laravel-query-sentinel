<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QuerySentinel\Support\Report;
use QuerySentinel\Support\Result;

final class ReportTest extends TestCase
{
    public function test_report_to_array_returns_all_fields(): void
    {
        $result = new Result(
            sql: 'SELECT 1',
            driver: 'mysql',
            explainRows: [],
            plan: '',
            metrics: [],
            scores: [],
            findings: [],
            executionTimeMs: 0.0,
        );

        $report = new Report(
            result: $result,
            grade: 'A',
            passed: true,
            summary: 'Test summary',
            recommendations: ['Do something'],
            compositeScore: 95.0,
            analyzedAt: new \DateTimeImmutable('2026-01-01 00:00:00'),
        );

        $array = $report->toArray();

        $this->assertSame('A', $array['grade']);
        $this->assertTrue($array['passed']);
        $this->assertSame('Test summary', $array['summary']);
        $this->assertSame(95.0, $array['composite_score']);
        $this->assertSame(['Do something'], $array['recommendations']);
        $this->assertArrayHasKey('result', $array);
        $this->assertArrayHasKey('analyzed_at', $array);
    }

    public function test_report_to_json_returns_valid_json(): void
    {
        $result = new Result(
            sql: 'SELECT 1',
            driver: 'mysql',
            explainRows: [],
            plan: '',
            metrics: [],
            scores: [],
            findings: [],
            executionTimeMs: 0.0,
        );

        $report = new Report(
            result: $result,
            grade: 'B',
            passed: true,
            summary: 'JSON test',
            recommendations: [],
            compositeScore: 80.0,
            analyzedAt: new \DateTimeImmutable('2026-01-01 00:00:00'),
        );

        $json = $report->toJson();
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertSame('B', $decoded['grade']);
        $this->assertEquals(80.0, $decoded['composite_score']);
    }
}
