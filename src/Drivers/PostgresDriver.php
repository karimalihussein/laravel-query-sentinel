<?php

declare(strict_types=1);

namespace QuerySentinel\Drivers;

use Illuminate\Support\Facades\DB;
use QuerySentinel\Contracts\DriverInterface;

final class PostgresDriver implements DriverInterface
{
    private ?string $version = null;

    public function __construct(
        private readonly ?string $connection = null,
    ) {}

    public function runExplain(string $sql): array
    {
        try {
            $results = DB::connection($this->connection)->select('EXPLAIN (FORMAT JSON) '.$sql);

            return array_map(fn ($row) => (array) $row, $results);
        } catch (\Exception) {
            return [];
        }
    }

    public function runExplainAnalyze(string $sql): string
    {
        try {
            $results = DB::connection($this->connection)->select('EXPLAIN (ANALYZE, FORMAT TEXT) '.$sql);

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
        return 'pgsql';
    }

    public function supportsAnalyze(): bool
    {
        return true;
    }

    public function normalizeAccessType(string $rawType): string
    {
        $lower = strtolower($rawType);

        return match (true) {
            str_contains($lower, 'index only scan') => 'covering_index_lookup',
            str_contains($lower, 'bitmap index scan'), str_contains($lower, 'bitmap heap scan') => 'index_range_scan',
            str_contains($lower, 'index scan') => 'index_lookup',
            str_contains($lower, 'seq scan') => 'table_scan',
            str_contains($lower, 'tid scan') => 'single_row_lookup',
            str_contains($lower, 'function scan') => 'table_scan',
            str_contains($lower, 'values scan') => 'const_row',
            default => 'unknown',
        };
    }

    public function normalizeJoinType(string $rawType): string
    {
        $lower = strtolower($rawType);

        return match (true) {
            str_contains($lower, 'nested loop') => 'nested_loop',
            str_contains($lower, 'hash join') => 'hash',
            str_contains($lower, 'merge join') => 'merge',
            default => 'unknown',
        };
    }

    public function runAnalyzeTable(string $table): void
    {
        DB::connection($this->connection)->statement("ANALYZE \"{$table}\"");
    }

    public function getColumnStats(string $table, string $column): array
    {
        try {
            $stats = DB::connection($this->connection)->selectOne(
                'SELECT n_distinct, null_frac, avg_width FROM pg_stats WHERE tablename = ? AND attname = ?',
                [$table, $column]
            );

            // Check for extended statistics
            $hasHistogram = false;
            if ($stats) {
                $histCheck = DB::connection($this->connection)->selectOne(
                    'SELECT most_common_vals IS NOT NULL AS has_mcv FROM pg_stats WHERE tablename = ? AND attname = ?',
                    [$table, $column]
                );
                $hasHistogram = (bool) ($histCheck->has_mcv ?? false);
            }

            return [
                'has_histogram' => $hasHistogram,
                'distinct_count' => $stats ? (int) $stats->n_distinct : null,
                'null_fraction' => $stats ? (float) $stats->null_frac : null,
                'avg_width' => $stats ? (int) $stats->avg_width : null,
            ];
        } catch (\Exception) {
            return ['has_histogram' => false, 'distinct_count' => null, 'null_fraction' => null, 'avg_width' => null];
        }
    }

    public function getCapabilities(): array
    {
        return [
            'histograms' => true,
            'explain_analyze' => true,
            'json_explain' => true,
            'covering_index_info' => true,
            'parallel_query' => true,
        ];
    }

    public function getVersion(): string
    {
        if ($this->version === null) {
            try {
                $result = DB::connection($this->connection)->select('SELECT version() as version');
                $this->version = $result[0]->version ?? '0.0.0';
            } catch (\Exception) {
                $this->version = '0.0.0';
            }
        }

        return $this->version;
    }
}
