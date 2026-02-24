<?php

declare(strict_types=1);

namespace QuerySentinel\Drivers;

use Illuminate\Support\Facades\DB;
use QuerySentinel\Contracts\DriverInterface;

final class MySqlDriver implements DriverInterface
{
    private ?string $version = null;

    public function __construct(
        private readonly ?string $connection = null,
    ) {}

    public function runExplain(string $sql): array
    {
        try {
            $results = DB::connection($this->connection)->select('EXPLAIN '.$sql);

            return array_map(fn ($row) => (array) $row, $results);
        } catch (\Exception) {
            return [];
        }
    }

    public function runExplainAnalyze(string $sql): string
    {
        try {
            if ($this->supportsAnalyze()) {
                $results = DB::connection($this->connection)->select('EXPLAIN ANALYZE '.$sql);
            } else {
                // Fallback: EXPLAIN FORMAT=TREE (MySQL 8.0.16+) or plain EXPLAIN
                $results = DB::connection($this->connection)->select('EXPLAIN FORMAT=TREE '.$sql);
            }

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
        return 'mysql';
    }

    public function supportsAnalyze(): bool
    {
        $version = $this->getVersion();

        // EXPLAIN ANALYZE requires MySQL 8.0.18+
        // Strip any suffix like "-MariaDB" or "-debug"
        $cleanVersion = preg_replace('/[^0-9.].*$/', '', $version);

        return version_compare($cleanVersion ?? '0.0.0', '8.0.18', '>=');
    }

    public function normalizeAccessType(string $rawType): string
    {
        return match (strtolower($rawType)) {
            'system', 'const' => 'const_row',
            'eq_ref' => 'single_row_lookup',
            'ref', 'ref_or_null' => 'index_lookup',
            'fulltext' => 'fulltext_lookup',
            'index_merge' => 'index_merge',
            'unique_subquery' => 'single_row_lookup',
            'index_subquery' => 'index_lookup',
            'range' => 'index_range_scan',
            'index' => 'index_scan',
            'all' => 'table_scan',
            default => 'unknown',
        };
    }

    public function normalizeJoinType(string $rawType): string
    {
        return match (strtolower($rawType)) {
            'inner join', 'join' => 'inner',
            'left join', 'left outer join' => 'left',
            'right join', 'right outer join' => 'right',
            'cross join' => 'cross',
            'natural join' => 'natural',
            'straight_join' => 'forced_inner',
            default => 'unknown',
        };
    }

    public function runAnalyzeTable(string $table): void
    {
        DB::connection($this->connection)->statement("ANALYZE TABLE `{$table}`");
    }

    public function getColumnStats(string $table, string $column): array
    {
        try {
            $dbName = DB::connection($this->connection)->getDatabaseName();

            // Check for histogram
            $hasHistogram = false;
            if ($this->supportsHistograms()) {
                $histResult = DB::connection($this->connection)->select(
                    'SELECT histogram FROM information_schema.COLUMN_STATISTICS WHERE schema_name = ? AND table_name = ? AND column_name = ?',
                    [$dbName, $table, $column]
                );
                $hasHistogram = !empty($histResult);
            }

            // Get basic column stats
            DB::connection($this->connection)->selectOne(
                'SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$dbName, $table, $column]
            );

            return [
                'has_histogram' => $hasHistogram,
                'distinct_count' => null,
                'null_fraction' => null,
                'avg_width' => null,
            ];
        } catch (\Exception) {
            return ['has_histogram' => false, 'distinct_count' => null, 'null_fraction' => null, 'avg_width' => null];
        }
    }

    public function getCapabilities(): array
    {
        return [
            'histograms' => $this->supportsHistograms(),
            'explain_analyze' => $this->supportsAnalyze(),
            'json_explain' => true,
            'covering_index_info' => true,
            'parallel_query' => false,
        ];
    }

    public function getVersion(): string
    {
        if ($this->version === null) {
            try {
                $result = DB::connection($this->connection)->select('SELECT VERSION() as version');
                $this->version = $result[0]->version ?? '0.0.0';
            } catch (\Exception) {
                $this->version = '0.0.0';
            }
        }

        return $this->version;
    }

    private function supportsHistograms(): bool
    {
        $cleanVersion = preg_replace('/[^0-9.].*$/', '', $this->getVersion());

        return version_compare($cleanVersion ?? '0.0.0', '8.0.0', '>=');
    }
}
