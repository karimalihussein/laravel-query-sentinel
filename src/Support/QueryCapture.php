<?php

declare(strict_types=1);

namespace QuerySentinel\Support;

/**
 * Immutable DTO representing a captured database query during profiling.
 *
 * Stores the raw SQL, bindings, execution time, and connection name
 * as captured by DB::listen().
 */
final readonly class QueryCapture
{
    /**
     * @param  array<int, mixed>  $bindings
     */
    public function __construct(
        public string $sql,
        public array $bindings,
        public float $timeMs,
        public ?string $connection = null,
    ) {}

    /**
     * Interpolate bindings into the SQL string for analysis.
     *
     * Uses simple positional replacement. Safe for EXPLAIN purposes
     * since the resulting SQL is never executed — only analyzed.
     */
    public function toInterpolatedSql(): string
    {
        if (empty($this->bindings)) {
            return $this->sql;
        }

        $sql = $this->sql;

        foreach ($this->bindings as $binding) {
            $value = match (true) {
                is_null($binding) => 'NULL',
                is_bool($binding) => $binding ? '1' : '0',
                is_int($binding) => (string) $binding,
                is_float($binding) => (string) $binding,
                default => "'".addslashes((string) $binding)."'",
            };

            $sql = (string) preg_replace('/\?/', $value, $sql, 1);
        }

        return $sql;
    }

    /**
     * Normalize SQL for duplicate/N+1 detection.
     *
     * Replaces literal values with placeholders to identify structurally
     * identical queries regardless of parameter values.
     */
    public function toNormalizedSql(): string
    {
        $sql = $this->sql;

        // Replace quoted strings with placeholder
        $sql = (string) preg_replace("/'[^']*'/", '?', $sql);

        // Replace numeric literals
        $sql = (string) preg_replace('/\b\d+\.?\d*\b/', '?', $sql);

        // Collapse whitespace
        $sql = (string) preg_replace('/\s+/', ' ', $sql);

        return trim($sql);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sql' => $this->sql,
            'bindings' => $this->bindings,
            'time_ms' => $this->timeMs,
            'connection' => $this->connection,
        ];
    }
}
