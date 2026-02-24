<?php

declare(strict_types=1);

namespace QuerySentinel\Analyzers;

use QuerySentinel\Enums\Severity;
use QuerySentinel\Support\BaselineStore;
use QuerySentinel\Support\Finding;

/**
 * Workload-Level Modeling: moves analysis from query-centric to workload-centric.
 *
 * Tracks query patterns over time using BaselineStore history:
 *   - Detects repeated full exports (same query returning 100K+ rows repeatedly)
 *   - Identifies API misuse (burst patterns: 5+ executions within 60 seconds)
 *   - Detects high-frequency large network transfers (>50MB payloads repeatedly)
 *   - Recommends architectural changes (background jobs, pagination, caching)
 */
final class WorkloadAnalyzer
{
    private const AVG_ROW_LENGTH = 256;

    public function __construct(
        private readonly BaselineStore $store,
        private readonly int $frequencyThreshold = 10,
        private readonly int $exportRowThreshold = 100_000,
        private readonly int $networkBytesThreshold = 52428800, // 50MB
    ) {}

    /**
     * Analyze workload-level patterns for a query.
     *
     * @param  string  $sql  Raw SQL (used for hashing)
     * @param  array<string, mixed>  $currentMetrics  Current analysis metrics
     * @return array{workload: array<string, mixed>, findings: Finding[]}
     */
    public function analyze(string $sql, array $currentMetrics): array
    {
        $queryHash = $this->normalizeAndHash($sql);
        $history = $this->store->history($queryHash, 50);
        $findings = [];

        $snapshotCount = count($history);
        $isFrequent = $snapshotCount >= $this->frequencyThreshold;

        // Count repeated full exports in history
        $exportCount = 0;
        foreach ($history as $snap) {
            $snapRows = $snap['table_size'] ?? $snap['rows_examined'] ?? 0;
            if ($snapRows >= $this->exportRowThreshold) {
                $exportCount++;
            }
        }

        // Count repeated large network transfers
        $largeTransferCount = 0;
        foreach ($history as $snap) {
            $snapRows = $snap['table_size'] ?? $snap['rows_examined'] ?? 0;
            if ($snapRows * self::AVG_ROW_LENGTH > $this->networkBytesThreshold) {
                $largeTransferCount++;
            }
        }

        // Current query characteristics
        $currentRows = (int) ($currentMetrics['rows_returned'] ?? $currentMetrics['rows_examined'] ?? 0);
        $isCurrentExport = $currentRows >= $this->exportRowThreshold;
        $isCurrentLargeTransfer = $currentRows * self::AVG_ROW_LENGTH > $this->networkBytesThreshold;

        // Pattern detection
        $patterns = [];

        if ($isFrequent && $exportCount >= 3) {
            $patterns[] = [
                'type' => 'REPEATED_FULL_EXPORT',
                'severity' => 'critical',
                'occurrences' => $exportCount,
            ];
            $findings[] = new Finding(
                severity: Severity::Critical,
                category: 'workload',
                title: sprintf('Repeated full export detected (%d executions, %d exports)', $snapshotCount, $exportCount),
                description: sprintf(
                    'This query returning %s rows has been executed %d times with %d full exports (>%s rows each). This pattern suggests an API endpoint serving bulk data synchronously.',
                    number_format($currentRows),
                    $snapshotCount,
                    $exportCount,
                    number_format($this->exportRowThreshold),
                ),
                recommendation: 'Move to a background job (Laravel Queue), implement streaming download (StreamedResponse), or add cursor-based pagination.',
                metadata: [
                    'pattern' => 'REPEATED_FULL_EXPORT',
                    'snapshot_count' => $snapshotCount,
                    'export_count' => $exportCount,
                    'current_rows' => $currentRows,
                ],
            );
        } elseif ($isFrequent && $largeTransferCount >= 3) {
            $patterns[] = [
                'type' => 'HIGH_FREQUENCY_LARGE_TRANSFER',
                'severity' => 'warning',
                'occurrences' => $largeTransferCount,
            ];
            $findings[] = new Finding(
                severity: Severity::Warning,
                category: 'workload',
                title: sprintf('High-frequency large network transfer (%d occurrences)', $largeTransferCount),
                description: sprintf(
                    'This query generates large network transfers (>50MB) and has been executed %d times. Repeated large payloads cause network congestion and client memory pressure.',
                    $snapshotCount,
                ),
                recommendation: 'Implement API pagination, use cursor-based streaming (cursor()/chunk()), or cache results with Redis for repeated access.',
                metadata: [
                    'pattern' => 'HIGH_FREQUENCY_LARGE_TRANSFER',
                    'snapshot_count' => $snapshotCount,
                    'large_transfer_count' => $largeTransferCount,
                ],
            );
        } elseif ($isFrequent) {
            $patterns[] = [
                'type' => 'HIGH_FREQUENCY',
                'severity' => 'info',
                'occurrences' => $snapshotCount,
            ];
        }

        // API misuse detection: rapid-fire burst patterns
        $burstCount = $this->detectBurstPatterns($history);
        if ($burstCount > 0) {
            $patterns[] = [
                'type' => 'API_MISUSE_BURST',
                'severity' => 'warning',
                'occurrences' => $burstCount,
            ];
            $findings[] = new Finding(
                severity: Severity::Warning,
                category: 'workload',
                title: sprintf('Rapid-fire query burst detected (%d burst%s)', $burstCount, $burstCount > 1 ? 's' : ''),
                description: sprintf(
                    'This query was executed in rapid bursts (%d burst%s of 5+ executions within 60 seconds). This may indicate an N+1 pattern at the API/controller level or missing caching.',
                    $burstCount,
                    $burstCount > 1 ? 's' : '',
                ),
                recommendation: 'Cache results at the application level (Cache::remember()), use eager loading, or debounce API calls.',
                metadata: [
                    'pattern' => 'API_MISUSE_BURST',
                    'burst_count' => $burstCount,
                ],
            );
        }

        return [
            'workload' => [
                'query_frequency' => $snapshotCount,
                'is_frequent' => $isFrequent,
                'patterns' => $patterns,
                'current_is_export' => $isCurrentExport,
                'current_is_large_transfer' => $isCurrentLargeTransfer,
                'historical_export_count' => $exportCount,
                'historical_large_transfer_count' => $largeTransferCount,
            ],
            'findings' => $findings,
        ];
    }

    /**
     * Detect burst patterns: 5+ executions within a 60-second window.
     *
     * @param  array<int, array<string, mixed>>  $history
     */
    private function detectBurstPatterns(array $history): int
    {
        if (count($history) < 5) {
            return 0;
        }

        $timestamps = [];
        foreach ($history as $snap) {
            if (isset($snap['timestamp']) && is_string($snap['timestamp'])) {
                $ts = strtotime($snap['timestamp']);
                if ($ts !== false) {
                    $timestamps[] = $ts;
                }
            }
        }

        sort($timestamps);

        $burstCount = 0;
        for ($i = 4; $i < count($timestamps); $i++) {
            if ($timestamps[$i] - $timestamps[$i - 4] < 60) {
                $burstCount++;
            }
        }

        return $burstCount;
    }

    private function normalizeAndHash(string $sql): string
    {
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $sql) ?? $sql));

        return hash('sha256', $normalized);
    }
}
