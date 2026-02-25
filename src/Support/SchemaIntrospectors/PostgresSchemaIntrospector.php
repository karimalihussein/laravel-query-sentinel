<?php

declare(strict_types=1);

namespace QuerySentinel\Support\SchemaIntrospectors;

use Illuminate\Database\Connection;
use QuerySentinel\Contracts\SchemaIntrospector;

final class PostgresSchemaIntrospector implements SchemaIntrospector
{
    public function tableExists(Connection $conn, string $table): ?object
    {
        return $conn->selectOne(
            "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename = ?",
            [$table]
        );
    }

    public function listTables(Connection $conn): array
    {
        $rows = $conn->select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");

        return array_map(fn ($r) => (object) ['TABLE_NAME' => $r->tablename], $rows);
    }

    public function columnExists(Connection $conn, string $dbName, string $table, string $column): ?object
    {
        return $conn->selectOne(
            "SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? AND column_name = ?",
            [$table, $column]
        );
    }

    public function listColumns(Connection $conn, string $dbName, string $table): array
    {
        $rows = $conn->select(
            "SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ?",
            [$table]
        );

        return array_map(fn ($r) => (object) ['COLUMN_NAME' => $r->column_name], $rows);
    }
}
