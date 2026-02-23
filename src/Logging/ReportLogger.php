<?php

declare(strict_types=1);

namespace QuerySentinel\Logging;

use Illuminate\Support\Facades\Log;
use QuerySentinel\Core\ProfileReport;

/**
 * Structured JSON logger for query profiling reports.
 *
 * Writes structured performance data to a configurable Laravel log channel.
 * Each log entry includes class/method context, query statistics, grade,
 * and actionable warnings for observability pipelines.
 */
final class ReportLogger
{
    /**
     * Log a profile report to the specified channel.
     */
    public function log(
        ProfileReport $report,
        string $class,
        string $method,
        string $channel = 'performance',
    ): void {
        $data = $this->buildPayload($report, $class, $method);
        $level = $this->determineLevel($report);

        Log::channel($channel)->log($level, 'QuerySentinel profile', $data);
    }

    /**
     * Build the structured log payload.
     *
     * @return array<string, mixed>
     */
    private function buildPayload(ProfileReport $report, string $class, string $method): array
    {
        $slowestTimeMs = $report->slowestQuery
            ? $report->slowestQuery->result->executionTimeMs
            : 0.0;

        $totalRowsExamined = 0;
        foreach ($report->individualReports as $r) {
            $totalRowsExamined += (int) ($r->result->metrics['rows_examined'] ?? 0);
        }

        $warnings = [];
        foreach ($report->individualReports as $r) {
            foreach ($r->result->findings as $finding) {
                if (in_array($finding['severity'] ?? '', ['critical', 'warning'], true)) {
                    $warnings[] = $finding['title'] ?? $finding['description'] ?? 'unknown';
                }
            }
        }

        return [
            'type' => 'query_sentinel_profile',
            'class' => $class,
            'method' => $method,
            'total_queries' => $report->totalQueries,
            'analyzed_queries' => $report->analyzedQueries,
            'cumulative_time_ms' => $report->cumulativeTimeMs,
            'slowest_query_ms' => round($slowestTimeMs, 2),
            'rows_examined' => $totalRowsExamined,
            'grade' => $report->worstGrade(),
            'n_plus_one' => $report->nPlusOneDetected,
            'duplicate_queries' => count($report->duplicateQueries),
            'warnings' => $warnings,
            'memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'analyzed_at' => $report->analyzedAt->format('c'),
        ];
    }

    /**
     * Determine log level from report severity.
     */
    private function determineLevel(ProfileReport $report): string
    {
        $grade = $report->worstGrade();

        if (in_array($grade, ['D', 'F'], true)) {
            return 'error';
        }

        if ($grade === 'C' || $report->nPlusOneDetected) {
            return 'warning';
        }

        return 'info';
    }
}
