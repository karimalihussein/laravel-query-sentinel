<?php

declare(strict_types=1);

namespace QuerySentinel\Adapters;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use QuerySentinel\Contracts\AdapterInterface;
use QuerySentinel\Contracts\AnalyzerInterface;
use QuerySentinel\Support\ExecutionGuard;
use QuerySentinel\Support\Report;
use QuerySentinel\Support\SqlSanitizer;

/**
 * Query Builder / Eloquent Builder adapter.
 *
 * Extracts SQL and bindings from a Laravel Builder without executing it,
 * interpolates bindings safely, then delegates to the core analyzer.
 *
 * Safety guarantees:
 *   - Never calls get(), first(), or any execution method
 *   - Never mutates the builder
 *   - Validates the extracted SQL is SELECT-only
 *   - Handles subqueries and unions via toSql()
 */
final class BuilderAdapter implements AdapterInterface
{
    public function __construct(
        private readonly AnalyzerInterface $analyzer,
        private readonly ExecutionGuard $guard,
        private readonly SqlSanitizer $sanitizer,
    ) {}

    /**
     * Analyze a Builder instance.
     *
     * @param  EloquentBuilder|QueryBuilder  $input
     */
    public function analyze(mixed $input): Report
    {
        $query = $this->resolveQueryBuilder($input);

        $rawSql = $query->toSql();
        $bindings = $query->getBindings();
        $connection = $query->getConnection();

        $interpolatedSql = $this->interpolateBindings($rawSql, $bindings, $connection);

        $sql = $this->sanitizer->sanitize($interpolatedSql);
        $this->guard->validate($sql);

        return $this->analyzer->analyze($sql, 'builder');
    }

    /**
     * Resolve to the base Query\Builder regardless of input type.
     */
    private function resolveQueryBuilder(mixed $builder): QueryBuilder
    {
        if ($builder instanceof EloquentBuilder) {
            return $builder->toBase();
        }

        if ($builder instanceof QueryBuilder) {
            return $builder;
        }

        throw new \InvalidArgumentException(sprintf(
            'BuilderAdapter expects %s or %s, got %s.',
            EloquentBuilder::class,
            QueryBuilder::class,
            get_debug_type($builder),
        ));
    }

    /**
     * Safely interpolate bindings into SQL for EXPLAIN purposes.
     *
     * The resulting SQL is never executed directly — it's only used
     * for EXPLAIN ANALYZE via the driver. Uses PDO::quote() for
     * proper escaping through the active database connection.
     *
     * @param  array<int, mixed>  $bindings
     */
    private function interpolateBindings(
        string $sql,
        array $bindings,
        \Illuminate\Database\ConnectionInterface $connection,
    ): string {
        if (empty($bindings)) {
            return $sql;
        }

        $pdo = $connection->getPdo();

        foreach ($bindings as $binding) {
            $value = match (true) {
                is_null($binding) => 'NULL',
                is_bool($binding) => $binding ? '1' : '0',
                is_int($binding) => (string) $binding,
                is_float($binding) => (string) $binding,
                default => $pdo->quote((string) $binding),
            };

            $sql = (string) preg_replace('/\?/', $value, $sql, 1);
        }

        return $sql;
    }
}
