<?php

declare(strict_types=1);

namespace QuerySentinel\Support\SchemaIntrospectors;

use Illuminate\Database\Connection;
use QuerySentinel\Contracts\SchemaIntrospector;

/**
 * No-op introspector for testing: reports all tables and columns as existing.
 * No driver-specific SQL; no database round-trips. Use in tests when schema
 * validation should pass without real schema (e.g. CI without migrations).
 */
final class PermissiveSchemaIntrospector implements SchemaIntrospector
{
    public function tableExists(Connection $conn, string $table): ?object
    {
        return (object) ['TABLE_NAME' => $table];
    }

    public function listTables(Connection $conn): array
    {
        return [];
    }

    public function columnExists(Connection $conn, string $dbName, string $table, string $column): ?object
    {
        return (object) ['COLUMN_NAME' => $column];
    }

    public function listColumns(Connection $conn, string $dbName, string $table): array
    {
        return [];
    }
}
