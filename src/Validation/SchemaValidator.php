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
     * Skips validation for: (1) columns from derived tables (alias => null),
     * (2) virtual/derived column aliases (e.g. ROW_NUMBER() AS rn, expression AS alias).
     *
     * @param  array<string, string|null>  $aliasToTable  alias => base table name, or null for derived tables
     */
    public function validateColumns(string $sql, array $aliasToTable): ValidationResult
    {
        $refs = SqlParser::extractColumnReferences($sql);
        $tables = SqlParser::extractTables($sql);
        $virtualColumns = SqlParser::extractVirtualColumnAliases($sql);
        $conn = DB::connection($this->connection ?? config('query-diagnostics.connection'));
        $dbName = $conn->getDatabaseName();

        foreach ($refs as ['table' => $tableOrAlias, 'column' => $column]) {
            // Resolve table: qualified ref uses alias map (null = derived table); unqualified uses first physical table
            $table = null;
            if ($tableOrAlias !== null) {
                $table = array_key_exists($tableOrAlias, $aliasToTable)
                    ? $aliasToTable[$tableOrAlias]
                    : $tableOrAlias;
                // Derived table alias (value null): skip physical schema check
                if ($table === null) {
                    continue;
                }
            } else {
                // Unqualified column: if it's a virtual/derived alias (e.g. rn from ROW_NUMBER() AS rn), skip
                if (in_array($column, $virtualColumns, true)) {
                    continue;
                }
                $table = $tables[0] ?? null;
            }

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
