<?php

declare(strict_types=1);

namespace QuerySentinel\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use QuerySentinel\Core\Engine;
use QuerySentinel\Exceptions\EngineAbortException;
use QuerySentinel\Scanner\AttributeScanner;
use QuerySentinel\Scanner\ScannedMethod;

/**
 * Interactive Artisan command that discovers methods annotated with
 * #[DiagnoseQuery], allows selection, extracts the Builder SQL,
 * and runs full EXPLAIN ANALYZE diagnostics.
 *
 * Production-safe: all method invocations are wrapped in a transaction
 * that is always rolled back. No persistent writes occur.
 */
final class ScanCommand extends Command
{
    /** @var string */
    protected $signature = 'query:scan
        {--json : Output report as JSON}
        {--connection= : Database connection to use}
        {--filter= : Filter methods by class, method, or label name}
        {--list : List all discovered methods without interactive selection}
        {--fail-on-warning : Exit with non-zero code if warnings found}';

    /** @var string */
    protected $description = 'Scan for #[DiagnoseQuery] methods and interactively diagnose query builders';

    public function handle(Engine $engine, AttributeScanner $scanner): int
    {
        $this->info('Scanning for #[DiagnoseQuery] methods...');
        $methods = $scanner->scan();

        if (empty($methods)) {
            $this->warn('No methods found with #[DiagnoseQuery] attribute.');
            $this->newLine();
            $this->line('  Add #[DiagnoseQuery] to methods that return a Query Builder:');
            $this->newLine();
            $this->line('  use QuerySentinel\Attributes\DiagnoseQuery;');
            $this->newLine();
            $this->line('  #[DiagnoseQuery(label: \'My slow query\')]');
            $this->line('  public function myQuery(): Builder { ... }');

            return self::SUCCESS;
        }

        $methods = $this->applyFilter($methods);
        if ($methods === []) {
            return self::SUCCESS;
        }

        $invocable = $this->filterInvocable($methods);
        if ($invocable === []) {
            $this->error('No invocable methods found (all have required parameters).');

            return self::FAILURE;
        }

        if ($this->option('list')) {
            return $this->renderMethodList($invocable);
        }

        $selected = $this->selectMethod($invocable);
        if ($selected === null) {
            return self::SUCCESS;
        }

        return $this->diagnoseMethod($engine, $selected);
    }

    /**
     * Apply --filter option to narrow results.
     *
     * @param  ScannedMethod[]  $methods
     * @return ScannedMethod[]
     */
    private function applyFilter(array $methods): array
    {
        $filter = $this->option('filter');
        if ($filter === null) {
            return $methods;
        }

        /** @var string $filter */
        $filtered = array_values(array_filter(
            $methods,
            fn (ScannedMethod $m) => stripos($m->className, $filter) !== false
                || stripos($m->methodName, $filter) !== false
                || stripos($m->attribute->label, $filter) !== false,
        ));

        if (empty($filtered)) {
            $this->warn("No methods match filter: {$filter}");
        }

        return $filtered;
    }

    /**
     * Filter out methods with required parameters.
     *
     * @param  ScannedMethod[]  $methods
     * @return ScannedMethod[]
     */
    private function filterInvocable(array $methods): array
    {
        $invocable = [];

        foreach ($methods as $method) {
            if ($method->hasRequiredParameters()) {
                $this->warn(sprintf(
                    '  Skipping %s — has required parameters (not supported)',
                    $method->qualifiedName(),
                ));
            } else {
                $invocable[] = $method;
            }
        }

        return $invocable;
    }

    /**
     * Render a table of discovered methods (--list mode).
     *
     * @param  ScannedMethod[]  $methods
     */
    private function renderMethodList(array $methods): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode(
                array_map(fn (ScannedMethod $m) => $m->toArray(), $methods),
                JSON_PRETTY_PRINT,
            ));

            return self::SUCCESS;
        }

        $this->info(sprintf('Found %d diagnosable method(s):', count($methods)));
        $this->newLine();

        $rows = [];
        foreach ($methods as $i => $method) {
            $rows[] = [
                $i + 1,
                $method->displayLabel(),
                $method->qualifiedName(),
                basename($method->filePath).':'.$method->lineNumber,
            ];
        }

        $this->table(['#', 'Label', 'Method', 'Location'], $rows);

        return self::SUCCESS;
    }

    /**
     * Present interactive choice for method selection.
     *
     * @param  ScannedMethod[]  $methods
     */
    private function selectMethod(array $methods): ?ScannedMethod
    {
        $this->info(sprintf('Found %d diagnosable method(s):', count($methods)));
        $this->newLine();

        $labels = [];
        foreach ($methods as $method) {
            $label = sprintf(
                '%s  (%s:%d)',
                $method->displayLabel(),
                basename($method->filePath),
                $method->lineNumber,
            );
            if ($method->attribute->description !== '') {
                $label .= ' — '.$method->attribute->description;
            }
            $labels[] = $label;
        }

        /** @var string $selected */
        $selected = $this->choice('Select a method to diagnose', $labels);

        $index = array_search($selected, $labels, true);
        if ($index === false) {
            return null;
        }

        return $methods[$index];
    }

    /**
     * Execute the selected method, extract Builder SQL, and run diagnostics.
     */
    private function diagnoseMethod(Engine $engine, ScannedMethod $method): int
    {
        $this->newLine();
        $this->info(sprintf('Diagnosing %s...', $method->qualifiedName()));
        $this->newLine();

        // 1. Resolve class from container
        $instance = $this->resolveInstance($method);
        if ($instance === null) {
            return self::FAILURE;
        }

        // 2. Call method inside transaction for safety
        $builder = $this->invokeMethodSafely($instance, $method);
        if ($builder === null) {
            return self::FAILURE;
        }

        // 3. Validate return type is Builder
        if (! $builder instanceof EloquentBuilder && ! $builder instanceof QueryBuilder) {
            $this->error(sprintf(
                'Method %s returned %s — expected Eloquent\\Builder or Query\\Builder.',
                $method->qualifiedName(),
                get_debug_type($builder),
            ));

            return self::FAILURE;
        }

        // 4. Extract SQL + bindings
        $interpolatedSql = $this->extractSql($builder);

        $this->renderSourceContext($method);
        $this->renderExtractedSql($interpolatedSql);

        // 5. Run full diagnostic pipeline
        return $this->runDiagnostics($engine, $interpolatedSql, $method);
    }

    /**
     * Resolve the class from the Laravel container.
     */
    private function resolveInstance(ScannedMethod $method): ?object
    {
        try {
            return app($method->className);
        } catch (\Throwable $e) {
            $this->error(sprintf('Failed to resolve %s: %s', $method->className, $e->getMessage()));

            return null;
        }
    }

    /**
     * Invoke the method inside a transaction that is always rolled back.
     *
     * Supports private/protected methods via ReflectionMethod::setAccessible().
     */
    private function invokeMethodSafely(object $instance, ScannedMethod $method): mixed
    {
        try {
            $connectionName = $this->option('connection');
            /** @var string|null $connectionName */
            $connection = DB::connection($connectionName);

            $reflection = new \ReflectionMethod($instance, $method->methodName);
            $reflection->setAccessible(true);

            $connection->beginTransaction();

            try {
                $result = $reflection->invoke($instance);
            } finally {
                try {
                    $connection->rollBack();
                } catch (\Throwable) {
                    // Transaction may not exist if method failed before DB interaction
                }
            }

            return $result;
        } catch (\Throwable $e) {
            $this->error(sprintf('Method invocation failed: %s', $e->getMessage()));

            return null;
        }
    }

    /**
     * Extract interpolated SQL from a Builder instance.
     *
     * Uses the same pattern as BuilderAdapter::interpolateBindings().
     *
     * @param  EloquentBuilder<\Illuminate\Database\Eloquent\Model>|QueryBuilder  $builder
     */
    private function extractSql(EloquentBuilder|QueryBuilder $builder): string
    {
        $query = $builder instanceof EloquentBuilder ? $builder->toBase() : $builder;

        $rawSql = $query->toSql();
        $bindings = $query->getBindings();
        /** @var \Illuminate\Database\Connection $connection */
        $connection = $query->getConnection();

        return $this->interpolateBindings($rawSql, $bindings, $connection);
    }

    /**
     * Interpolate bindings into SQL for EXPLAIN purposes.
     *
     * @param  array<int, mixed>  $bindings
     */
    private function interpolateBindings(
        string $sql,
        array $bindings,
        \Illuminate\Database\Connection $connection,
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

    private function renderSourceContext(ScannedMethod $method): void
    {
        $this->line('  '.str_repeat('-', 70));
        $this->line(sprintf('  Diagnosed Method:'));
        $this->line(sprintf('  Class:   %s', $method->className));
        $this->line(sprintf('  Method:  %s', $method->methodName));
        $this->line(sprintf('  File:    %s:%d', $method->filePath, $method->lineNumber));
        if ($method->attribute->label !== '') {
            $this->line(sprintf('  Label:   %s', $method->attribute->label));
        }
        $this->line('  '.str_repeat('-', 70));
        $this->newLine();
    }

    private function renderExtractedSql(string $sql): void
    {
        $this->line('<fg=yellow>  Extracted SQL:</>');
        $this->line('  '.str_repeat('-', 70));
        $this->line('  '.$sql);
        $this->line('  '.str_repeat('-', 70));
        $this->newLine();
    }

    /**
     * Run Engine::diagnose() and render the report.
     */
    private function runDiagnostics(Engine $engine, string $sql, ScannedMethod $method): int
    {
        $this->info('Running EXPLAIN ANALYZE...');

        $renderer = new ReportRenderer;
        $connectionName = $this->option('connection');

        try {
            /** @var string|null $connectionName */
            $diagnostic = $engine->diagnose($sql, $connectionName);
        } catch (EngineAbortException $e) {
            if ($this->option('json')) {
                $this->line($e->failureReport->toJson(JSON_PRETTY_PRINT));
            } else {
                $renderer->renderValidationFailure($this, $e->failureReport, $sql);
            }

            return self::FAILURE;
        }

        if ($diagnostic->report->isValidationFailure() && $diagnostic->report->validationFailure !== null) {
            if ($this->option('json')) {
                $this->line($diagnostic->report->validationFailure->toJson(JSON_PRETTY_PRINT));
            } else {
                $renderer->renderValidationFailure($this, $diagnostic->report->validationFailure, $sql);
            }

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $data = $diagnostic->toArray();
            $data['source'] = $method->toArray();
            $this->line((string) json_encode($data, JSON_PRETTY_PRINT));
        } else {
            $renderer->render($this, $diagnostic);
        }

        if ($this->option('fail-on-warning') && ! $diagnostic->report->passed) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
