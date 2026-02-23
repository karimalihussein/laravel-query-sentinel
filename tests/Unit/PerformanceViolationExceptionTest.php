<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QuerySentinel\Core\ProfileReport;
use QuerySentinel\Exceptions\PerformanceViolationException;
use QuerySentinel\Support\Report;
use QuerySentinel\Support\Result;

final class PerformanceViolationExceptionTest extends TestCase
{
    public function test_basic_construction(): void
    {
        $report = $this->createEmptyReport();

        $exception = new PerformanceViolationException(
            report: $report,
            reason: 'test reason',
            class: 'App\Http\Controllers\TestController',
            method: 'index',
        );

        $this->assertStringContainsString('TestController', $exception->getMessage());
        $this->assertStringContainsString('index', $exception->getMessage());
        $this->assertStringContainsString('test reason', $exception->getMessage());
        $this->assertSame($report, $exception->report);
        $this->assertSame('App\Http\Controllers\TestController', $exception->class);
        $this->assertSame('index', $exception->method);
    }

    public function test_from_report_detects_bad_grade(): void
    {
        $report = $this->createReportWithGrade('F', 10.0);

        $exception = PerformanceViolationException::fromReport(
            $report,
            'App\TestClass',
            'search',
        );

        $this->assertStringContainsString('grade F', $exception->getMessage());
    }

    public function test_from_report_detects_slow_query(): void
    {
        $report = $this->createReportWithSlowQuery(750.0);

        $exception = PerformanceViolationException::fromReport(
            $report,
            'App\TestClass',
            'index',
        );

        $this->assertStringContainsString('slow query', $exception->getMessage());
        $this->assertStringContainsString('750', $exception->getMessage());
    }

    public function test_from_report_detects_n_plus_one(): void
    {
        $report = $this->createReportWithNPlusOne();

        $exception = PerformanceViolationException::fromReport(
            $report,
            'App\TestClass',
            'list',
        );

        $this->assertStringContainsString('N+1', $exception->getMessage());
    }

    public function test_from_report_detects_table_scan(): void
    {
        $report = $this->createReportWithTableScan();

        $exception = PerformanceViolationException::fromReport(
            $report,
            'App\TestClass',
            'filter',
        );

        $this->assertStringContainsString('full table scan', $exception->getMessage());
    }

    public function test_from_report_combines_multiple_reasons(): void
    {
        $individualReport = new Report(
            result: new Result(
                sql: 'SELECT * FROM users',
                driver: 'mysql',
                explainRows: [],
                plan: '',
                metrics: ['has_table_scan' => true],
                scores: [],
                findings: [],
                executionTimeMs: 750.0,
            ),
            grade: 'F',
            passed: false,
            summary: 'Bad query',
            recommendations: [],
            compositeScore: 15.0,
            analyzedAt: new \DateTimeImmutable,
        );

        $report = new ProfileReport(
            mode: 'profiler',
            totalQueries: 1,
            analyzedQueries: 1,
            cumulativeTimeMs: 750.0,
            slowestQuery: $individualReport,
            worstQuery: $individualReport,
            duplicateQueries: [],
            nPlusOneDetected: true,
            individualReports: [$individualReport],
            captures: [],
            queryCounts: [],
            skippedQueries: [],
            analyzedAt: new \DateTimeImmutable,
        );

        $exception = PerformanceViolationException::fromReport($report, 'App\Test', 'run');

        $message = $exception->getMessage();
        $this->assertStringContainsString('grade F', $message);
        $this->assertStringContainsString('slow query', $message);
        $this->assertStringContainsString('N+1', $message);
        $this->assertStringContainsString('full table scan', $message);
    }

    public function test_from_report_fallback_reason(): void
    {
        $report = $this->createEmptyReport();

        $exception = PerformanceViolationException::fromReport($report, 'App\Test', 'run');

        $this->assertStringContainsString('critical findings', $exception->getMessage());
    }

    private function createEmptyReport(): ProfileReport
    {
        return new ProfileReport(
            mode: 'profiler',
            totalQueries: 0,
            analyzedQueries: 0,
            cumulativeTimeMs: 0.0,
            slowestQuery: null,
            worstQuery: null,
            duplicateQueries: [],
            nPlusOneDetected: false,
            individualReports: [],
            captures: [],
            queryCounts: [],
            skippedQueries: [],
            analyzedAt: new \DateTimeImmutable,
        );
    }

    private function createReportWithGrade(string $grade, float $score): ProfileReport
    {
        $individualReport = new Report(
            result: new Result(
                sql: 'SELECT * FROM users',
                driver: 'mysql',
                explainRows: [],
                plan: '',
                metrics: [],
                scores: [],
                findings: [],
                executionTimeMs: 10.0,
            ),
            grade: $grade,
            passed: false,
            summary: 'Test',
            recommendations: [],
            compositeScore: $score,
            analyzedAt: new \DateTimeImmutable,
        );

        return new ProfileReport(
            mode: 'profiler',
            totalQueries: 1,
            analyzedQueries: 1,
            cumulativeTimeMs: 10.0,
            slowestQuery: $individualReport,
            worstQuery: $individualReport,
            duplicateQueries: [],
            nPlusOneDetected: false,
            individualReports: [$individualReport],
            captures: [],
            queryCounts: [],
            skippedQueries: [],
            analyzedAt: new \DateTimeImmutable,
        );
    }

    private function createReportWithSlowQuery(float $timeMs): ProfileReport
    {
        $individualReport = new Report(
            result: new Result(
                sql: 'SELECT * FROM users',
                driver: 'mysql',
                explainRows: [],
                plan: '',
                metrics: [],
                scores: [],
                findings: [],
                executionTimeMs: $timeMs,
            ),
            grade: 'B',
            passed: true,
            summary: 'Test',
            recommendations: [],
            compositeScore: 80.0,
            analyzedAt: new \DateTimeImmutable,
        );

        return new ProfileReport(
            mode: 'profiler',
            totalQueries: 1,
            analyzedQueries: 1,
            cumulativeTimeMs: $timeMs,
            slowestQuery: $individualReport,
            worstQuery: $individualReport,
            duplicateQueries: [],
            nPlusOneDetected: false,
            individualReports: [$individualReport],
            captures: [],
            queryCounts: [],
            skippedQueries: [],
            analyzedAt: new \DateTimeImmutable,
        );
    }

    private function createReportWithNPlusOne(): ProfileReport
    {
        return new ProfileReport(
            mode: 'profiler',
            totalQueries: 5,
            analyzedQueries: 0,
            cumulativeTimeMs: 50.0,
            slowestQuery: null,
            worstQuery: null,
            duplicateQueries: [],
            nPlusOneDetected: true,
            individualReports: [],
            captures: [],
            queryCounts: [],
            skippedQueries: [],
            analyzedAt: new \DateTimeImmutable,
        );
    }

    private function createReportWithTableScan(): ProfileReport
    {
        $individualReport = new Report(
            result: new Result(
                sql: 'SELECT * FROM users',
                driver: 'mysql',
                explainRows: [],
                plan: '',
                metrics: ['has_table_scan' => true],
                scores: [],
                findings: [],
                executionTimeMs: 10.0,
            ),
            grade: 'B',
            passed: true,
            summary: 'Test',
            recommendations: [],
            compositeScore: 80.0,
            analyzedAt: new \DateTimeImmutable,
        );

        return new ProfileReport(
            mode: 'profiler',
            totalQueries: 1,
            analyzedQueries: 1,
            cumulativeTimeMs: 10.0,
            slowestQuery: $individualReport,
            worstQuery: $individualReport,
            duplicateQueries: [],
            nPlusOneDetected: false,
            individualReports: [$individualReport],
            captures: [],
            queryCounts: [],
            skippedQueries: [],
            analyzedAt: new \DateTimeImmutable,
        );
    }
}
