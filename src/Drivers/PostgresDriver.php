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
