<?php

declare(strict_types=1);

namespace QuerySentinel\Validation;

use QuerySentinel\Support\SqlParser;
use QuerySentinel\Support\ValidationResult;

/**
 * Fail-safe validation pipeline. Returns ValidationResult; callers decide whether to abort.
 *
 * Order: Schema (tables) → Schema (columns) → Joins → Syntax (EXPLAIN)
 * Schema before Syntax so we can give precise "table/column not found" errors.
 */
final class ValidationPipeline
{
    public function __construct(
        private readonly ?string $connection = null,
        private readonly ?\QuerySentinel\Contracts\SchemaIntrospector $introspector = null,
        private readonly ?\QuerySentinel\Contracts\DriverInterface $driver = null,
    ) {}

    /**
     * Run full validation. Returns ValidationResult (invalid on first failure).
     */
    public function validate(string $sql): ValidationResult
    {
        $aliasToTable = SqlParser::extractTableAliases($sql);

        $introspector = $this->introspector;
        $driver = $this->driver;

        if ($introspector === null && function_exists('app') && app()->bound(\QuerySentinel\Contracts\SchemaIntrospector::class)) {
            $introspector = app()->make(\QuerySentinel\Contracts\SchemaIntrospector::class);
        }

        if ($driver === null && function_exists('app') && app()->bound(\QuerySentinel\Contracts\DriverInterface::class)) {
            $driver = app()->make(\QuerySentinel\Contracts\DriverInterface::class);
        }

        if ($introspector === null) {
            return ValidationResult::invalid([
                new \QuerySentinel\Support\ValidationFailureReport(
                    status: 'ERROR — Validation Unavailable',
                    failureStage: 'Pipeline',
                    detailedError: 'SchemaIntrospector not available',
                    recommendations: ['Ensure QuerySentinel is properly configured.'],
                ),
            ]);
        }

        $schemaValidator = new SchemaValidator($introspector, $this->connection);
        $result = $schemaValidator->validateTables($sql);
        if (! $result->isValid()) {
            return $result;
        }

        $result = $result->merge($schemaValidator->validateColumns($sql, $aliasToTable));
        if (! $result->isValid()) {
            return $result;
        }

        $joinValidator = new JoinValidator;
        $result = $result->merge($joinValidator->validate($sql, $aliasToTable));
        if (! $result->isValid()) {
            return $result;
        }

        $syntaxValidator = new SyntaxValidator($this->connection, $driver);
        return $result->merge($syntaxValidator->validate($sql));
    }
}
