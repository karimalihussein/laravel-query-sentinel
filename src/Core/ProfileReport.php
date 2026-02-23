<?php

declare(strict_types=1);

namespace QuerySentinel\Core;

use QuerySentinel\Support\QueryCapture;
use QuerySentinel\Support\Report;

/**
 * Aggregated profiler report for multiple captured queries.
 *
 * Contains individual query reports plus aggregate statistics:
 * total query count, cumulative time, N+1 detection, duplicate
 * detection, slowest query, and worst-scoring query.
 */
final readonly class ProfileReport
{
    /**
     * @param  array<int, Report>  $individualReports
     * @param  array<int, QueryCapture>  $captures
     * @param  array<string, int>  $duplicateQueries  normalized_sql => count (only duplicates)
     * @param  array<string, int>  $queryCounts  normalized_sql => count (all)
     * @param  array<int, string>  $skippedQueries  SQL strings that were non-SELECT
     */
    public function __construct(
        public string $mode,
        public int $totalQueries,
        public int $analyzedQueries,
        public float $cumulativeTimeMs,
        public ?Report $slowestQuery,
        public ?Report $worstQuery,
        public array $duplicateQueries,
        public bool $nPlusOneDetected,
        public array $individualReports,
        public array $captures,
        public array $queryCounts,
        public array $skippedQueries,
        public \DateTimeImmutable $analyzedAt,
    ) {}

    /**
     * Build a ProfileReport from captures and their analyzed reports.
     *
     * @param  array<int, QueryCapture>  $captures
     * @param  array<int, Report>  $reports
     * @param  array<int, string>  $skippedQueries
     */
    public static function fromCaptures(
        array $captures,
        array $reports,
        array $skippedQueries = [],
        int $nPlusOneThreshold = 3,
    ): self {
        $totalQueries = count($captures);
        $analyzedQueries = count($reports);

        // Cumulative time from captures (actual DB time, not EXPLAIN time)
        $cumulativeTimeMs = 0.0;
        foreach ($captures as $capture) {
            $cumulativeTimeMs += $capture->timeMs;
        }

        // Query counts by normalized SQL
        $queryCounts = [];
        foreach ($captures as $capture) {
            $normalized = $capture->toNormalizedSql();
            $queryCounts[$normalized] = ($queryCounts[$normalized] ?? 0) + 1;
        }

        // Duplicate queries: normalized SQL appearing more than once
        $duplicateQueries = array_filter($queryCounts, fn (int $count) => $count > 1);

        // N+1 detection: any normalized SQL appearing >= threshold times
        $nPlusOneDetected = false;
        foreach ($queryCounts as $count) {
            if ($count >= $nPlusOneThreshold) {
                $nPlusOneDetected = true;

                break;
            }
        }

        // Slowest query by execution time
        $slowestQuery = null;
        $slowestTime = 0.0;
        foreach ($reports as $report) {
            $time = $report->result->executionTimeMs;
            if ($time > $slowestTime) {
                $slowestTime = $time;
                $slowestQuery = $report;
            }
        }

        // Worst query by lowest composite score
        $worstQuery = null;
        $worstScore = 101.0;
        foreach ($reports as $report) {
            if ($report->compositeScore < $worstScore) {
                $worstScore = $report->compositeScore;
                $worstQuery = $report;
            }
        }

        return new self(
            mode: 'profiler',
            totalQueries: $totalQueries,
            analyzedQueries: $analyzedQueries,
            cumulativeTimeMs: round($cumulativeTimeMs, 2),
            slowestQuery: $slowestQuery,
            worstQuery: $worstQuery,
            duplicateQueries: $duplicateQueries,
            nPlusOneDetected: $nPlusOneDetected,
            individualReports: $reports,
            captures: $captures,
            queryCounts: $queryCounts,
            skippedQueries: $skippedQueries,
            analyzedAt: new \DateTimeImmutable,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'total_queries' => $this->totalQueries,
            'analyzed_queries' => $this->analyzedQueries,
            'cumulative_time_ms' => $this->cumulativeTimeMs,
            'slowest_query' => $this->slowestQuery?->toArray(),
            'worst_query' => $this->worstQuery?->toArray(),
            'duplicate_queries' => $this->duplicateQueries,
            'n_plus_one_detected' => $this->nPlusOneDetected,
            'query_counts' => $this->queryCounts,
            'skipped_queries' => $this->skippedQueries,
            'individual_reports' => array_map(
                fn (Report $r) => $r->toArray(),
                $this->individualReports,
            ),
            'analyzed_at' => $this->analyzedAt->format('c'),
        ];
    }

    public function toJson(int $flags = 0): string
    {
        return json_encode($this->toArray(), $flags | JSON_THROW_ON_ERROR);
    }

    /**
     * Get the overall worst grade across all analyzed queries.
     */
    public function worstGrade(): string
    {
        $gradeOrder = ['F' => 0, 'D' => 1, 'C' => 2, 'B' => 3, 'A' => 4];
        $worst = 'A';

        foreach ($this->individualReports as $report) {
            $current = $gradeOrder[$report->grade] ?? 5;
            $worstVal = $gradeOrder[$worst] ?? 5;

            if ($current < $worstVal) {
                $worst = $report->grade;
            }
        }

        return $worst;
    }

    /**
     * Check if any analyzed query has critical findings.
     */
    public function hasCriticalFindings(): bool
    {
        foreach ($this->individualReports as $report) {
            if (! $report->passed) {
                return true;
            }
        }

        return false;
    }
}
