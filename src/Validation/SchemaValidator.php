<?php

declare(strict_types=1);

namespace QuerySentinel\Validation;

use Illuminate\Support\Facades\DB;
use QuerySentinel\Contracts\SchemaIntrospector;
use QuerySentinel\Support\SqlParser;
use QuerySentinel\Support\TypoIntelligence;
use QuerySentinel\Support\ValidationFailureReport;
use QuerySentinel\Support\ValidationResult;

/**
 * Validates table and column existence via driver-specific introspector.
 * Returns ValidationResult; no exceptions.
 */
final class SchemaValidator
{
    public function __construct(
        private readonly SchemaIntrospector $introspector,
        private readonly ?string $connection = null,
    ) {}

    /**
     * Validate all tables exist. Returns ValidationResult (invalid on first missing table).
     */
    public function validateTables(string $sql): ValidationResult
    {
        $tables = SqlParser::extractTables($sql);
        $conn = DB::connection($this->connection ?? config('query-diagnostics.connection'));

        foreach ($tables as $table) {
            $exists = $this->introspector->tableExists($conn, $table);

            if ($exists === null) {
                $dbName = $conn->getDatabaseName();
                $existing = $this->introspector->listTables($conn);
                $candidates = array_column($existing, 'TABLE_NAME');
                $suggestion = TypoIntelligence::suggest($table, $candidates);

                $recs = [
                    'Check table name spelling',
                    'Verify current database',
                ];
                if ($suggestion !== null) {
                    $recs[] = "Did you mean: {$suggestion}?";
                }

                return ValidationResult::invalid([
                    new ValidationFailureReport(
                        status: 'ERROR — Table Not Found',
                        failureStage: 'Table Validation',
                        detailedError: "Table '{$dbName}.{$table}' doesn't exist",
                        recommendations: $recs,
                        suggestion: $suggestion,
                        missingTable: $table,
                        database: $dbName,
                    ),
                ]);
            }
        }

        return ValidationResult::valid();
    }

    /**
     * Validate all column references exist. Returns ValidationResult (invalid on first missing column).
     *
     * @param  array<string, string>  $aliasToTable
     */
    public function validateColumns(string $sql, array $aliasToTable): ValidationResult
    {
        $refs = SqlParser::extractColumnReferences($sql);
        $tables = SqlParser::extractTables($sql);
        $conn = DB::connection($this->connection ?? config('query-diagnostics.connection'));
        $dbName = $conn->getDatabaseName();

        foreach ($refs as ['table' => $tableOrAlias, 'column' => $column]) {
            $table = $tableOrAlias !== null
                ? ($aliasToTable[$tableOrAlias] ?? $tableOrAlias)
                : ($tables[0] ?? null);

            if ($table === null) {
                continue;
            }

            $exists = $this->introspector->columnExists($conn, $dbName, $table, $column);

            if ($exists === null) {
                $existing = $this->introspector->listColumns($conn, $dbName, $table);
                $candidates = array_column($existing, 'COLUMN_NAME');
                $suggestion = TypoIntelligence::suggest($column, $candidates);

                $recs = $suggestion !== null ? ["Did you mean: {$suggestion}?"] : ['Check column name spelling'];

                return ValidationResult::invalid([
                    new ValidationFailureReport(
                        status: 'ERROR — Column Not Found',
                        failureStage: 'Column Validation',
                        detailedError: "Column '{$table}.{$column}' does not exist",
                        recommendations: $recs,
                        suggestion: $suggestion,
                        missingTable: $table,
                        missingColumn: $column,
                        database: $dbName,
                    ),
                ]);
            }
        }

        return ValidationResult::valid();
    }
}
