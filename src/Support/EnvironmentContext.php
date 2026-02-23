<?php

declare(strict_types=1);

namespace QuerySentinel\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * MySQL server environment snapshot.
 *
 * Captures server configuration and buffer pool state to contextualize
 * performance analysis. Cached per-session (300s TTL, scoped by DB name).
 */
final readonly class EnvironmentContext
{
    public function __construct(
        public string $mysqlVersion,
        public int $bufferPoolSizeBytes,
        public int $innodbIoCapacity,
        public int $innodbPageSize,
        public int $tmpTableSize,
        public int $maxHeapTableSize,
        public float $bufferPoolUtilization,
        public bool $isColdCache,
        public string $databaseName,
    ) {}

    /**
     * Collect environment context from MySQL server variables and status.
     */
    public static function collect(?string $connectionName = null): self
    {
        $connection = DB::connection($connectionName);
        $dbName = $connection->getDatabaseName();
        $cacheKey = 'query_sentinel_env_context_'.$dbName;

        return Cache::remember($cacheKey, 300, function () use ($connection, $dbName) {
            $vars = $connection->selectOne('SELECT
                @@version AS ver,
                @@innodb_buffer_pool_size AS bp_size,
                @@innodb_io_capacity AS io_cap,
                @@innodb_page_size AS page_size,
                @@tmp_table_size AS tmp_size,
                @@max_heap_table_size AS heap_size
            ');

            $statusRows = $connection->select("SHOW STATUS WHERE Variable_name IN (
                'Innodb_buffer_pool_pages_total',
                'Innodb_buffer_pool_pages_data'
            )");
            $status = collect($statusRows)->pluck('Value', 'Variable_name');
            $total = (int) ($status['Innodb_buffer_pool_pages_total'] ?? 1);
            $data = (int) ($status['Innodb_buffer_pool_pages_data'] ?? 0);
            $utilization = $total > 0 ? $data / $total : 0.0;

            return new self(
                mysqlVersion: $vars->ver,
                bufferPoolSizeBytes: (int) $vars->bp_size,
                innodbIoCapacity: (int) $vars->io_cap,
                innodbPageSize: (int) $vars->page_size,
                tmpTableSize: (int) $vars->tmp_size,
                maxHeapTableSize: (int) $vars->heap_size,
                bufferPoolUtilization: round($utilization, 4),
                isColdCache: $utilization < 0.5,
                databaseName: $dbName,
            );
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'mysql_version' => $this->mysqlVersion,
            'buffer_pool_size_bytes' => $this->bufferPoolSizeBytes,
            'buffer_pool_size_mb' => round($this->bufferPoolSizeBytes / (1024 * 1024), 1),
            'innodb_io_capacity' => $this->innodbIoCapacity,
            'innodb_page_size' => $this->innodbPageSize,
            'tmp_table_size' => $this->tmpTableSize,
            'max_heap_table_size' => $this->maxHeapTableSize,
            'buffer_pool_utilization' => $this->bufferPoolUtilization,
            'is_cold_cache' => $this->isColdCache,
            'database_name' => $this->databaseName,
        ];
    }
}
