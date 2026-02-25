<?php

declare(strict_types=1);

namespace QuerySentinel\Drivers;

use Illuminate\Support\Facades\DB;
use QuerySentinel\Contracts\DriverInterface;

final class SqliteDriver implements DriverInterface
{
    public function __construct(
        private readonly ?string $connection = null,
    ) {}

    public function runExplain(string $sql): array
    {
        try {
            // Use EXPLAIN QUERY PLAN for human-readable plan
            $results = DB::connection($this->connection)->select('EXPLAIN QUERY PLAN '.$sql);

            return array_map(fn ($row) => (array) $row, $results);
        } catch (\Exception) {
            return [];
        }
    }

    public function runExplainAnalyze(string $sql): string
    {
        try {
            $results = DB::connection($this->connection)->select('EXPLAIN QUERY PLAN '.$sql);

            return collect($results)
                ->map(fn ($row) => (array) $row)
                ->map(fn ($arr) => reset($arr) ?: '')
                ->implode("\n");
        } catch (\Exception $e) {
            return '-- EXPLAIN failed: '.$e->getMessage();
        }
    }

    public function getName(): string
    {
        return 'sqlite';
    }

    public function supportsAnalyze(): bool
    {
        return false;
    }

    public function normalizeAccessType(string $rawType): string
    {
        return 'unknown';
    }

    public function normalizeJoinType(string $rawType): string
    {
        return 'unknown';
    }

    public function runAnalyzeTable(string $table): void
    {
        // SQLite ANALYZE exists but may be no-op; best-effort
        DB::connection($this->connection)->statement('ANALYZE "'.$table.'"');
    }

    public function getColumnStats(string $table, string $column): array
    {
        return ['has_histogram' => false, 'distinct_count' => null, 'null_fraction' => null, 'avg_width' => null];
    }

    public function getCapabilities(): array
    {
        return [
            'histograms' => false,
            'explain_analyze' => false,
            'json_explain' => false,
            'covering_index_info' => false,
            'parallel_query' => false,
        ];
    }

    public function getVersion(): string
    {
        try {
            $result = DB::connection($this->connection)->selectOne('SELECT sqlite_version() as version');

            return $result->version ?? '0.0.0';
        } catch (\Exception) {
            return '0.0.0';
        }
    }
}
