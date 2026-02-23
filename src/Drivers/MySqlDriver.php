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
}
