<?php

declare(strict_types=1);

namespace QuerySentinel\Analyzers;

use QuerySentinel\Enums\Severity;
use QuerySentinel\Support\EnvironmentContext;
use QuerySentinel\Support\ExecutionProfile;
use QuerySentinel\Support\Finding;

/**
 * Phase 7: Memory Pressure Model.
 *
 * Estimates query memory footprint: sort buffers, join buffers, temp tables,
 * disk spill, and buffer pool pressure. Classifies risk as low/moderate/high.
 *
 * Memory domains:
 *   - Buffer pool working set (shared, not multiplied by concurrency)
 *   - Execution memory: sort/join/temp buffers (per-session, multiplied)
 *   - Network transfer (per-session, multiplied)
 */
final class MemoryPressureAnalyzer
{
    /** Default average row length estimate in bytes */
    private const DEFAULT_AVG_ROW_LENGTH = 256;

    /** Default sort_buffer_size in bytes (256KB) */
    private const DEFAULT_SORT_BUFFER_SIZE = 262144;

    /** Default join_buffer_size in bytes (256KB) */
    private const DEFAULT_JOIN_BUFFER_SIZE = 262144;

    /** Network transfer warning threshold in bytes (50 MB) */
    private const NETWORK_TRANSFER_WARNING_BYTES = 52428800;

    private int $highThresholdBytes;

    private int $moderateThresholdBytes;

    public function __construct(
        int $highThresholdBytes = 268435456,    // 256MB
        int $moderateThresholdBytes = 67108864,  // 64MB
        private int $concurrentSessions = 1,
    ) {
        $this->highThresholdBytes = $highThresholdBytes;
        $this->moderateThresholdBytes = $moderateThresholdBytes;
    }

    /**
     * @param  array<string, mixed>  $metrics  From MetricsExtractor
     * @param  EnvironmentContext|null  $environment  Server config snapshot
     * @param  ExecutionProfile|null  $profile  Execution profile data
     * @return array{memory_pressure: array<string, mixed>, findings: Finding[]}
     */
    public function analyze(array $metrics, ?EnvironmentContext $environment, ?ExecutionProfile $profile): array
    {
        $findings = [];

        $rowsExamined = $metrics['rows_examined'] ?? 0;
        $hasFilesort = $metrics['has_filesort'] ?? false;
        $hasTempTable = $metrics['has_temp_table'] ?? false;
        $hasDiskTemp = $metrics['has_disk_temp'] ?? false;
        $joinCount = $metrics['join_count'] ?? 0;

        $avgRowLength = self::DEFAULT_AVG_ROW_LENGTH;
        $sortBufferSize = self::DEFAULT_SORT_BUFFER_SIZE;
        $joinBufferSize = self::DEFAULT_JOIN_BUFFER_SIZE;
        $tmpTableSize = $environment?->tmpTableSize ?? 16777216; // 16MB default
        $bufferPoolSizeBytes = $environment?->bufferPoolSizeBytes ?? 134217728; // 128MB default
        $innodbPageSize = $environment?->innodbPageSize ?? 16384;

        // Component calculations
        $sortBufferBytes = 0;
        if ($hasFilesort) {
            $sortBufferBytes = min($sortBufferSize, $rowsExamined * $avgRowLength);
        }

        $joinBufferBytes = 0;
        if ($joinCount > 1) {
            $joinBufferBytes = ($joinCount - 1) * $joinBufferSize;
        }

        $tempTableBytes = 0;
        if ($hasTempTable) {
            $tempTableBytes = min($tmpTableSize, $rowsExamined * $avgRowLength);
        }

        $diskSpillBytes = 0;
        if ($hasDiskTemp) {
            $diskSpillBytes = $rowsExamined * $avgRowLength;
        }

        // Working-set estimation: use physical reads (actual disk pages) when available,
        // otherwise estimate unique pages from row count and average row size.
        $physicalReads = $profile?->physicalReads ?? 0;
        if ($physicalReads > 0) {
            $bufferPoolReadsBytes = $physicalReads * $innodbPageSize;
        } else {
            $uniquePagesEstimated = (int) ceil(($rowsExamined * $avgRowLength) / max($innodbPageSize, 1));
            $bufferPoolReadsBytes = $uniquePagesEstimated * $innodbPageSize;
        }

        // Network transfer estimate: rows returned × avg row length
        $rowsReturned = $metrics['rows_returned'] ?? $rowsExamined;
        $networkTransferBytes = $rowsReturned * $avgRowLength;

        // Categorize memory into workload components
        $executionMemoryBytes = $sortBufferBytes + $joinBufferBytes + $tempTableBytes + $diskSpillBytes;
        $bufferPoolWorkingSetBytes = $bufferPoolReadsBytes;

        $totalEstimatedBytes = $executionMemoryBytes + $bufferPoolWorkingSetBytes;
        $bufferPoolPressure = $bufferPoolSizeBytes > 0
            ? round($bufferPoolWorkingSetBytes / $bufferPoolSizeBytes, 4)
            : 0.0;

        // Concurrency-adjusted estimates
        // Execution memory multiplies per session (each session has its own sort/join/temp buffers)
        // Buffer pool working set does NOT multiply (shared resource)
        // Network transfer multiplies per session
        $concurrentExecutionMemory = $executionMemoryBytes * $this->concurrentSessions;
        $concurrentNetworkTransfer = $networkTransferBytes * $this->concurrentSessions;
        $concurrentTotalEstimated = $concurrentExecutionMemory + $bufferPoolWorkingSetBytes;

        // Risk classification uses concurrency-adjusted totals
        $memoryRisk = 'low';
        if ($concurrentTotalEstimated > $this->highThresholdBytes || $bufferPoolPressure > 0.5) {
            $memoryRisk = 'high';
        } elseif ($concurrentTotalEstimated > $this->moderateThresholdBytes || $bufferPoolPressure > 0.2) {
            $memoryRisk = 'moderate';
        }

        // Build recommendations
        $recommendations = [];
        if ($diskSpillBytes > 0) {
            $recommendations[] = sprintf(
                'Increase tmp_table_size and max_heap_table_size to at least %s to avoid disk-based temp tables.',
                $this->formatBytes($diskSpillBytes)
            );
        }
        if ($bufferPoolPressure > 0.3) {
            // Proportional recommendation: target 70% utilization
            $recommendedBytes = (int) ceil($bufferPoolWorkingSetBytes / 0.7);
            $recommendedGb = max(1, (int) pow(2, ceil(log(max($recommendedBytes / 1073741824, 1), 2))));

            if ($bufferPoolPressure > 0.5) {
                $recommendations[] = sprintf(
                    'Buffer pool pressure is %.0f%% (working set: %s of %s pool). Increase innodb_buffer_pool_size to at least %d GB.',
                    $bufferPoolPressure * 100,
                    $this->formatBytes($bufferPoolWorkingSetBytes),
                    $this->formatBytes($bufferPoolSizeBytes),
                    $recommendedGb,
                );
            } else {
                $recommendations[] = sprintf(
                    'Buffer pool pressure is %.0f%%. Consider increasing innodb_buffer_pool_size to %d GB under concurrent load.',
                    $bufferPoolPressure * 100,
                    $recommendedGb,
                );
            }
        }
        if ($sortBufferBytes > $sortBufferSize * 0.9) {
            $recommendations[] = 'Sort buffer may be undersized. Consider increasing sort_buffer_size for this session or adding an index to avoid filesort.';
        }
        if ($joinBufferBytes > $this->moderateThresholdBytes) {
            $recommendations[] = 'Join buffers are consuming significant memory. Add indexes on join columns to reduce buffer requirements.';
        }

        // Generate findings
        if ($memoryRisk === 'high') {
            $findings[] = new Finding(
                severity: Severity::Warning,
                category: 'memory_pressure',
                title: sprintf('High memory pressure: %s estimated', $this->formatBytes($concurrentTotalEstimated)),
                description: sprintf(
                    'Query estimated memory footprint is %s (single: %s, %dx concurrent). Components: sort buffer %s, join buffers %s, temp table %s, disk spill %s, buffer pool reads %s.',
                    $this->formatBytes($concurrentTotalEstimated),
                    $this->formatBytes($totalEstimatedBytes),
                    $this->concurrentSessions,
                    $this->formatBytes($sortBufferBytes),
                    $this->formatBytes($joinBufferBytes),
                    $this->formatBytes($tempTableBytes),
                    $this->formatBytes($diskSpillBytes),
                    $this->formatBytes($bufferPoolReadsBytes),
                ),
                recommendation: implode(' ', $recommendations) ?: null,
                metadata: [
                    'total_bytes' => $concurrentTotalEstimated,
                    'memory_risk' => $memoryRisk,
                ],
            );
        } elseif ($memoryRisk === 'moderate') {
            $findings[] = new Finding(
                severity: Severity::Optimization,
                category: 'memory_pressure',
                title: sprintf('Moderate memory pressure: %s estimated', $this->formatBytes($concurrentTotalEstimated)),
                description: sprintf(
                    'Query memory footprint is moderate at %s (%dx concurrent). Monitor under concurrent load.',
                    $this->formatBytes($concurrentTotalEstimated),
                    $this->concurrentSessions,
                ),
                recommendation: implode(' ', $recommendations) ?: null,
                metadata: [
                    'total_bytes' => $concurrentTotalEstimated,
                    'memory_risk' => $memoryRisk,
                ],
            );
        }

        if ($hasDiskTemp) {
            $findings[] = new Finding(
                severity: Severity::Warning,
                category: 'memory_pressure',
                title: 'Disk-based temporary table spill',
                description: sprintf(
                    'Temporary table exceeded tmp_table_size (%s) and spilled to disk. Estimated disk I/O: %s.',
                    $this->formatBytes($tmpTableSize),
                    $this->formatBytes($diskSpillBytes),
                ),
                recommendation: 'Increase tmp_table_size/max_heap_table_size or reduce result set size with more selective WHERE conditions.',
            );
        }

        // Network transfer warning
        if ($networkTransferBytes > self::NETWORK_TRANSFER_WARNING_BYTES) {
            $severity = $networkTransferBytes > (self::NETWORK_TRANSFER_WARNING_BYTES * 2)
                ? Severity::Warning       // > 100MB
                : Severity::Optimization; // > 50MB

            $findings[] = new Finding(
                severity: $severity,
                category: 'memory_pressure',
                title: sprintf('Large network transfer: %s estimated', $this->formatBytes($networkTransferBytes)),
                description: sprintf(
                    'Query returns %s rows with an estimated network payload of %s. This can cause client memory pressure, slow response times, and network congestion.',
                    number_format($rowsReturned),
                    $this->formatBytes($networkTransferBytes),
                ),
                recommendation: 'Use cursor()/chunk() for streaming, add LIMIT/OFFSET pagination, or reduce columns.',
                metadata: [
                    'network_transfer_bytes' => $networkTransferBytes,
                    'rows_returned' => $rowsReturned,
                ],
            );
        }

        // Network pressure classification (first-class field)
        $networkPressure = 'LOW';
        if ($networkTransferBytes > self::NETWORK_TRANSFER_WARNING_BYTES * 4) {
            $networkPressure = 'CRITICAL'; // > 200MB
        } elseif ($networkTransferBytes > self::NETWORK_TRANSFER_WARNING_BYTES * 2) {
            $networkPressure = 'HIGH'; // > 100MB
        } elseif ($networkTransferBytes > self::NETWORK_TRANSFER_WARNING_BYTES) {
            $networkPressure = 'MODERATE'; // > 50MB
        }

        return [
            'memory_pressure' => [
                'components' => [
                    'sort_buffer_bytes' => $sortBufferBytes,
                    'join_buffer_bytes' => $joinBufferBytes,
                    'temp_table_bytes' => $tempTableBytes,
                    'disk_spill_bytes' => $diskSpillBytes,
                    'buffer_pool_reads_bytes' => $bufferPoolReadsBytes,
                ],
                'categories' => [
                    'buffer_pool_working_set' => $bufferPoolWorkingSetBytes,
                    'execution_memory' => $executionMemoryBytes,
                    'network_transfer_estimate' => $networkTransferBytes,
                ],
                'total_estimated_bytes' => $totalEstimatedBytes,
                'buffer_pool_pressure' => $bufferPoolPressure,
                'memory_risk' => $memoryRisk,
                'network_pressure' => $networkPressure,
                'recommendations' => $recommendations,
                'concurrency_adjusted' => [
                    'concurrent_sessions' => $this->concurrentSessions,
                    'execution_memory_per_session' => $executionMemoryBytes,
                    'concurrent_execution_memory' => $concurrentExecutionMemory,
                    'concurrent_network_transfer' => $concurrentNetworkTransfer,
                    'concurrent_total_estimated' => $concurrentTotalEstimated,
                ],
            ],
            'findings' => $findings,
        ];
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return sprintf('%.1f GB', $bytes / 1073741824);
        }
        if ($bytes >= 1048576) {
            return sprintf('%.1f MB', $bytes / 1048576);
        }
        if ($bytes >= 1024) {
            return sprintf('%.1f KB', $bytes / 1024);
        }

        return sprintf('%d B', $bytes);
    }
}
