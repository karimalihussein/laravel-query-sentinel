<?php

declare(strict_types=1);

namespace QuerySentinel\Validation;

use QuerySentinel\Exceptions\EngineAbortException;
use QuerySentinel\Support\SqlParser;

/**
 * Fail-safe validation pipeline. MUST pass before analysis.
 *
 * Order: Schema (tables) → Schema (columns) → Joins → Syntax (EXPLAIN) → EXPLAIN ANALYZE
 *
 * Schema before Syntax because EXPLAIN fails for both missing tables and syntax errors;
 * we validate schema first to give precise "table/column not found" errors.
 */
final class ValidationPipeline
{
    public function __construct(
        private readonly ?string $connection = null,
    ) {}

    /**
     * Run full validation. Throws EngineAbortException on first failure.
     *
     * @throws EngineAbortException
     */
    public function validate(string $sql): void
    {
        $aliasToTable = SqlParser::extractTableAliases($sql);

        // 1. Table existence
        $schemaValidator = new SchemaValidator($this->connection);
        $schemaValidator->validateTables($sql);

        // 2. Column existence
        $schemaValidator->validateColumns($sql, $aliasToTable);

        // 3. Join conditions
        $joinValidator = new JoinValidator;
        $joinValidator->validate($sql, $aliasToTable);

        // 4. Syntax (EXPLAIN without ANALYZE — at this point schema is valid)
        $syntaxValidator = new SyntaxValidator($this->connection);
        $syntaxValidator->validate($sql);
    }
}
