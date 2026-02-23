<?php

declare(strict_types=1);

namespace QuerySentinel\Analyzers;

use QuerySentinel\Enums\Severity;
use QuerySentinel\Support\EnvironmentContext;
use QuerySentinel\Support\Finding;

/**
 * Section 1: Environment Context Layer.
 *
 * Collects MySQL server configuration (version, buffer pool, InnoDB settings)
 * and generates environment-specific findings that may affect timing accuracy.
 */
final class EnvironmentAnalyzer
{
    /**
     * @return array{context: EnvironmentContext, findings: Finding[]}
     */
    public function analyze(?string $connectionName = null): array
    {
        $context = EnvironmentContext::collect($connectionName);
        $findings = [];

        if ($context->isColdCache) {
            $findings[] = new Finding(
                severity: Severity::Info,
                category: 'environment',
                title: sprintf('Cold buffer pool: %.0f%% utilized', $context->bufferPoolUtilization * 100),
                description: 'Buffer pool utilization is below 50%, indicating the server may have recently restarted. Timing results may be pessimistic.',
            );
        }

        if ($context->bufferPoolSizeBytes < 256 * 1024 * 1024) {
            $sizeMb = round($context->bufferPoolSizeBytes / (1024 * 1024));
            $findings[] = new Finding(
                severity: Severity::Optimization,
                category: 'environment',
                title: sprintf('Buffer pool size: %d MB', $sizeMb),
                description: sprintf(
                    'innodb_buffer_pool_size is %d MB. For production workloads, 1-4 GB is recommended.',
                    $sizeMb
                ),
                recommendation: 'SET GLOBAL innodb_buffer_pool_size = 1073741824; -- 1 GB',
            );
        }

        $effectiveTemp = min($context->tmpTableSize, $context->maxHeapTableSize);
        if ($context->tmpTableSize !== $context->maxHeapTableSize) {
            $findings[] = new Finding(
                severity: Severity::Info,
                category: 'environment',
                title: 'tmp_table_size and max_heap_table_size differ',
                description: sprintf(
                    'tmp_table_size=%s, max_heap_table_size=%s. MySQL uses the smaller (%s) for in-memory temp tables.',
                    number_format($context->tmpTableSize),
                    number_format($context->maxHeapTableSize),
                    number_format($effectiveTemp)
                ),
                recommendation: 'Set both to the same value for predictable temp table behavior.',
            );
        }

        return ['context' => $context, 'findings' => $findings];
    }
}
