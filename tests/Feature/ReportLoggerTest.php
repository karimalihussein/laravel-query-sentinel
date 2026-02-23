<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Feature;

use Illuminate\Support\Facades\Log;
use QuerySentinel\Core\ProfileReport;
use QuerySentinel\Logging\ReportLogger;
use QuerySentinel\Support\Report;
use QuerySentinel\Support\Result;
use QuerySentinel\Tests\TestCase;

final class ReportLoggerTest extends TestCase
{
    private ReportLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new ReportLogger;
    }

    public function test_logs_to_specified_channel(): void
    {
        Log::shouldReceive('channel')
            ->with('performance')
            ->once()
            ->andReturnSelf();

        Log::shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                return $message === 'QuerySentinel profile'
                    && $context['type'] === 'query_sentinel_profile';
            });

        $this->logger->log(
            $this->createReport('A'),
            'App\Controllers\TestController',
            'index',
            'performance',
        );
    }

    public function test_log_payload_contains_all_fields(): void
    {
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $data) {
                $this->assertArrayHasKey('type', $data);
                $this->assertArrayHasKey('class', $data);
                $this->assertArrayHasKey('method', $data);
                $this->assertArrayHasKey('total_queries', $data);
                $this->assertArrayHasKey('analyzed_queries', $data);
                $this->assertArrayHasKey('cumulative_time_ms', $data);
                $this->assertArrayHasKey('slowest_query_ms', $data);
                $this->assertArrayHasKey('rows_examined', $data);
                $this->assertArrayHasKey('grade', $data);
                $this->assertArrayHasKey('n_plus_one', $data);
                $this->assertArrayHasKey('duplicate_queries', $data);
                $this->assertArrayHasKey('warnings', $data);
                $this->assertArrayHasKey('memory_mb', $data);
                $this->assertArrayHasKey('analyzed_at', $data);

                $this->assertSame('query_sentinel_profile', $data['type']);
                $this->assertSame('App\TestController', $data['class']);
                $this->assertSame('show', $data['method']);

                return true;
            });

        $this->logger->log($this->createReport('B'), 'App\TestController', 'show');
    }

    public function test_error_level_for_grade_f(): void
    {
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level) => $level === 'error');

        $this->logger->log($this->createReport('F'), 'App\Test', 'run');
    }

    public function test_error_level_for_grade_d(): void
    {
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level) => $level === 'error');

        $this->logger->log($this->createReport('D'), 'App\Test', 'run');
    }

    public function test_warning_level_for_grade_c(): void
    {
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level) => $level === 'warning');

        $this->logger->log($this->createReport('C'), 'App\Test', 'run');
    }

    public function test_info_level_for_grade_a(): void
    {
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level) => $level === 'info');

        $this->logger->log($this->createReport('A'), 'App\Test', 'run');
    }

    public function test_warning_level_for_n_plus_one(): void
    {
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('log')
            ->once()
            ->withArgs(fn (string $level) => $level === 'warning');

        $report = new ProfileReport(
            mode: 'profiler',
            totalQueries: 5,
            analyzedQueries: 1,
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

        $this->logger->log($report, 'App\Test', 'run');
    }

    public function test_custom_channel(): void
    {
        Log::shouldReceive('channel')
            ->with('slow-queries')
            ->once()
            ->andReturnSelf();

        Log::shouldReceive('log')->once();

        $this->logger->log($this->createReport('A'), 'App\Test', 'run', 'slow-queries');
    }

    public function test_extracts_warnings_from_findings(): void
    {
        $individualReport = new Report(
            result: new Result(
                sql: 'SELECT * FROM users',
                driver: 'mysql',
                explainRows: [],
                plan: '',
                metrics: [],
                scores: [],
                findings: [
                    ['severity' => 'critical', 'title' => 'Full table scan detected'],
                    ['severity' => 'warning', 'title' => 'Missing index'],
                    ['severity' => 'info', 'title' => 'Query uses covering index'],
                ],
                executionTimeMs: 10.0,
            ),
            grade: 'C',
            passed: true,
            summary: 'Test',
            recommendations: [],
            compositeScore: 60.0,
            analyzedAt: new \DateTimeImmutable,
        );

        $report = new ProfileReport(
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

        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $data) {
                // Should include critical and warning, but NOT info
                $this->assertCount(2, $data['warnings']);
                $this->assertContains('Full table scan detected', $data['warnings']);
                $this->assertContains('Missing index', $data['warnings']);

                return true;
            });

        $this->logger->log($report, 'App\Test', 'run');
    }

    private function createReport(string $grade): ProfileReport
    {
        $individualReport = new Report(
            result: new Result(
                sql: 'SELECT * FROM users',
                driver: 'mysql',
                explainRows: [],
                plan: '',
                metrics: ['rows_examined' => 1000],
                scores: [],
                findings: [],
                executionTimeMs: 25.0,
            ),
            grade: $grade,
            passed: $grade !== 'F' && $grade !== 'D',
            summary: 'Test',
            recommendations: [],
            compositeScore: match ($grade) {
                'A' => 95.0, 'B' => 80.0, 'C' => 60.0, 'D' => 30.0, 'F' => 10.0,
                default => 50.0,
            },
            analyzedAt: new \DateTimeImmutable,
        );

        return new ProfileReport(
            mode: 'profiler',
            totalQueries: 1,
            analyzedQueries: 1,
            cumulativeTimeMs: 25.0,
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
