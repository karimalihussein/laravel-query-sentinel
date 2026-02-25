<?php

declare(strict_types=1);

namespace QuerySentinel\Validation;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use QuerySentinel\Exceptions\EngineAbortException;
use QuerySentinel\Support\SqlParser;
use QuerySentinel\Support\TypoIntelligence;
use QuerySentinel\Support\ValidationFailureReport;

/**
 * Validates table and column existence via INFORMATION_SCHEMA.
 */
final class SchemaValidator
{
    public function __construct(
        private readonly ?string $connection = null,
    ) {}

    /**
     * Validate all tables exist. Throws on first missing table.
     *
     * @throws EngineAbortException
     */
    public function validateTables(string $sql): void
    {
        $tables = SqlParser::extractTables($sql);
        $conn = DB::connection($this->connection ?? config('query-diagnostics.connection'));
        $driver = $conn->getDriverName();

        foreach ($tables as $table) {
            $exists = $this->tableExists($conn, $driver, $table);

            if ($exists === null) {
                $dbName = $conn->getDatabaseName();
                $existing = $this->listTables($conn, $driver);
                $candidates = array_column($existing, 'TABLE_NAME');
                $suggestion = TypoIntelligence::suggest($table, $candidates);

                $recs = [
                    'Check table name spelling',
                    'Verify current database',
                ];
                if ($suggestion !== null) {
                    $recs[] = "Did you mean: {$suggestion}?";
                }

                throw new EngineAbortException(
                    'Table not found',
                    new ValidationFailureReport(
                        status: 'ERROR — Table Not Found',
                        failureStage: 'Table Validation',
                        detailedError: "Table '{$dbName}.{$table}' doesn't exist",
                        recommendations: $recs,
                        suggestion: $suggestion,
                        missingTable: $table,
                        database: $dbName,
                    )
                );
            }
        }
    }

    /**
     * Validate all column references exist. Throws on first missing column.
     *
     * @param  array<string, string>  $aliasToTable
     *
     * @throws EngineAbortException
     */
    public function validateColumns(string $sql, array $aliasToTable): void
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

            $driver = $conn->getDriverName();
            $exists = $this->columnExists($conn, $driver, $dbName, $table, $column);

            if ($exists === null) {
                $existing = $this->listColumns($conn, $driver, $dbName, $table);
                $candidates = array_column($existing, 'COLUMN_NAME');
                $suggestion = TypoIntelligence::suggest($column, $candidates);

                $recs = $suggestion !== null ? ["Did you mean: {$suggestion}?"] : ['Check column name spelling'];

                throw new EngineAbortException(
                    'Column not found',
                    new ValidationFailureReport(
                        status: 'ERROR — Column Not Found',
                        failureStage: 'Column Validation',
                        detailedError: "Column '{$table}.{$column}' does not exist",
                        recommendations: $recs,
                        suggestion: $suggestion,
                        missingTable: $table,
                        missingColumn: $column,
                        database: $dbName,
                    )
                );
            }
        }
    }

    private function tableExists(Connection $conn, string $driver, string $table): ?object
    {
        if ($driver === 'sqlite') {
            return $conn->selectOne(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
                [$table]
            );
        }
        $dbName = $conn->getDatabaseName();
        if ($driver === 'pgsql') {
            return $conn->selectOne(
                "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename = ?",
                [$table]
            );
        }

        return $conn->selectOne(
            'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$dbName, $table]
        );
    }

    /**
     * @return array<int, object>
     */
    private function listTables(Connection $conn, string $driver): array
    {
        if ($driver === 'sqlite') {
            $rows = $conn->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'");

            return array_map(fn ($r) => (object) ['TABLE_NAME' => $r->name], $rows);
        }
        $dbName = $conn->getDatabaseName();
        if ($driver === 'pgsql') {
            $rows = $conn->select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");

            return array_map(fn ($r) => (object) ['TABLE_NAME' => $r->tablename], $rows);
        }

        return $conn->select('SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ?', [$dbName]);
    }

    private function columnExists(Connection $conn, string $driver, string $dbName, string $table, string $column): ?object
    {
        if ($driver === 'sqlite') {
            $cols = $conn->select("PRAGMA table_info('".str_replace("'", "''", $table)."')");
            foreach ($cols as $c) {
                if (($c->name ?? null) === $column) {
                    return (object) ['COLUMN_NAME' => $column];
                }
            }

            return null;
        }
        if ($driver === 'pgsql') {
            return $conn->selectOne(
                "SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? AND column_name = ?",
                [$table, $column]
            );
        }

        return $conn->selectOne(
            'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$dbName, $table, $column]
        );
    }

    /**
     * @return array<int, object>
     */
    /**
     * @return array<int, object>
     */
    private function listColumns(Connection $conn, string $driver, string $dbName, string $table): array
    {
        if ($driver === 'sqlite') {
            $rows = $conn->select("PRAGMA table_info('".str_replace("'", "''", $table)."')");

            return array_map(fn ($r) => (object) ['COLUMN_NAME' => $r->name], $rows);
        }
        if ($driver === 'pgsql') {
            $rows = $conn->select(
                "SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ?",
                [$table]
            );

            return array_map(fn ($r) => (object) ['COLUMN_NAME' => $r->column_name], $rows);
        }

        return $conn->select(
            'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$dbName, $table]
        );
    }
}
