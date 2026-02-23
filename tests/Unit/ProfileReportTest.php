<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QuerySentinel\Core\ProfileReport;
use QuerySentinel\Support\QueryCapture;
use QuerySentinel\Support\Report;
use QuerySentinel\Support\Result;

final class ProfileReportTest extends TestCase
{
    public function test_from_empty_captures(): void
    {
        $report = ProfileReport::fromCaptures([], []);

        $this->assertSame('profiler', $report->mode);
        $this->assertSame(0, $report->totalQueries);
        $this->assertSame(0, $report->analyzedQueries);
        $this->assertSame(0.0, $report->cumulativeTimeMs);
        $this->assertNull($report->slowestQuery);
        $this->assertNull($report->worstQuery);
        $this->assertFalse($report->nPlusOneDetected);
        $this->assertEmpty($report->individualReports);
        $this->assertEmpty($report->duplicateQueries);
    }

    public function test_cumulative_time_sums_captures(): void
    {
        $captures = [
            new QueryCapture('SELECT 1', [], 5.0),
            new QueryCapture('SELECT 2', [], 10.0),
            new QueryCapture('SELECT 3', [], 3.5),
        ];

        $report = ProfileReport::fromCaptures($captures, []);

        $this->assertSame(18.5, $report->cumulativeTimeMs);
        $this->assertSame(3, $report->totalQueries);
    }

    public function test_duplicate_queries_detected(): void
    {
        $captures = [
            new QueryCapture('SELECT * FROM users WHERE id = ?', [1], 1.0),
            new QueryCapture('SELECT * FROM users WHERE id = ?', [2], 1.0),
            new QueryCapture('SELECT * FROM users WHERE id = ?', [3], 1.0),
            new QueryCapture('SELECT * FROM orders WHERE user_id = ?', [1], 2.0),
        ];

        $report = ProfileReport::fromCaptures($captures, []);

        $this->assertNotEmpty($report->duplicateQueries);
        $this->assertCount(1, $report->duplicateQueries);

        // The normalized SQL for "SELECT * FROM users WHERE id = ?" should appear 3 times
        $firstDuplicate = array_values($report->duplicateQueries)[0];
        $this->assertSame(3, $firstDuplicate);
    }

    public function test_n_plus_one_detected_at_threshold(): void
    {
        $captures = [
            new QueryCapture('SELECT * FROM users WHERE id = ?', [1], 1.0),
            new QueryCapture('SELECT * FROM users WHERE id = ?', [2], 1.0),
            new QueryCapture('SELECT * FROM users WHERE id = ?', [3], 1.0),
        ];

        // Default threshold is 3
        $report = ProfileReport::fromCaptures($captures, [], [], 3);
        $this->assertTrue($report->nPlusOneDetected);
    }

    public function test_n_plus_one_not_detected_below_threshold(): void
    {
        $captures = [
            new QueryCapture('SELECT * FROM users WHERE id = ?', [1], 1.0),
            new QueryCapture('SELECT * FROM users WHERE id = ?', [2], 1.0),
        ];

        $report = ProfileReport::fromCaptures($captures, [], [], 3);
        $this->assertFalse($report->nPlusOneDetected);
    }

    public function test_slowest_query_identified(): void
    {
        $reports = [
            $this->makeReport(sql: 'SELECT 1', timeMs: 5.0, compositeScore: 80.0),
            $this->makeReport(sql: 'SELECT 2', timeMs: 50.0, compositeScore: 90.0),
            $this->makeReport(sql: 'SELECT 3', timeMs: 10.0, compositeScore: 70.0),
        ];

        $profile = ProfileReport::fromCaptures([], $reports);

        $this->assertSame('SELECT 2', $profile->slowestQuery->result->sql);
    }

    public function test_worst_query_identified(): void
    {
        $reports = [
            $this->makeReport(sql: 'SELECT 1', timeMs: 5.0, compositeScore: 80.0),
            $this->makeReport(sql: 'SELECT 2', timeMs: 50.0, compositeScore: 90.0),
            $this->makeReport(sql: 'SELECT 3', timeMs: 10.0, compositeScore: 30.0),
        ];

        $profile = ProfileReport::fromCaptures([], $reports);

        $this->assertSame('SELECT 3', $profile->worstQuery->result->sql);
    }

    public function test_skipped_queries_tracked(): void
    {
        $skipped = ['INSERT INTO logs VALUES (1)', 'UPDATE stats SET count = count + 1'];

        $report = ProfileReport::fromCaptures([], [], $skipped);

        $this->assertCount(2, $report->skippedQueries);
        $this->assertSame($skipped, $report->skippedQueries);
    }

    public function test_to_array_has_required_keys(): void
    {
        $report = ProfileReport::fromCaptures([], []);
        $array = $report->toArray();

        $this->assertArrayHasKey('mode', $array);
        $this->assertArrayHasKey('total_queries', $array);
        $this->assertArrayHasKey('analyzed_queries', $array);
        $this->assertArrayHasKey('cumulative_time_ms', $array);
        $this->assertArrayHasKey('slowest_query', $array);
        $this->assertArrayHasKey('worst_query', $array);
        $this->assertArrayHasKey('duplicate_queries', $array);
        $this->assertArrayHasKey('n_plus_one_detected', $array);
        $this->assertArrayHasKey('individual_reports', $array);
        $this->assertArrayHasKey('query_counts', $array);
        $this->assertArrayHasKey('skipped_queries', $array);
        $this->assertArrayHasKey('analyzed_at', $array);
    }

    public function test_to_json_is_valid(): void
    {
        $report = ProfileReport::fromCaptures([], []);
        $json = $report->toJson();

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertSame('profiler', $decoded['mode']);
    }

    public function test_worst_grade_with_reports(): void
    {
        $reports = [
            $this->makeReport(grade: 'A', compositeScore: 95.0),
            $this->makeReport(grade: 'C', compositeScore: 60.0),
            $this->makeReport(grade: 'B', compositeScore: 80.0),
        ];

        $profile = ProfileReport::fromCaptures([], $reports);

        $this->assertSame('C', $profile->worstGrade());
    }

    public function test_worst_grade_empty_is_a(): void
    {
        $profile = ProfileReport::fromCaptures([], []);

        $this->assertSame('A', $profile->worstGrade());
    }

    public function test_has_critical_findings(): void
    {
        $reports = [
            $this->makeReport(passed: true),
            $this->makeReport(passed: false),
        ];

        $profile = ProfileReport::fromCaptures([], $reports);

        $this->assertTrue($profile->hasCriticalFindings());
    }

    public function test_no_critical_findings(): void
    {
        $reports = [
            $this->makeReport(passed: true),
            $this->makeReport(passed: true),
        ];

        $profile = ProfileReport::fromCaptures([], $reports);

        $this->assertFalse($profile->hasCriticalFindings());
    }

    private function makeReport(
        string $sql = 'SELECT 1',
        string $grade = 'A',
        bool $passed = true,
        float $timeMs = 1.0,
        float $compositeScore = 90.0,
    ): Report {
        $result = new Result(
            sql: $sql,
            driver: 'mysql',
            explainRows: [],
            plan: '',
            metrics: [],
            scores: ['grade' => $grade, 'composite_score' => $compositeScore],
            findings: [],
            executionTimeMs: $timeMs,
        );

        return new Report(
            result: $result,
            grade: $grade,
            passed: $passed,
            summary: 'test summary',
            recommendations: [],
            compositeScore: $compositeScore,
            analyzedAt: new \DateTimeImmutable,
        );
    }
}
