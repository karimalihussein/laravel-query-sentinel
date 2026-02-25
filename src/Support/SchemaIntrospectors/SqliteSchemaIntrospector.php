<?php

declare(strict_types=1);

namespace QuerySentinel\Support\SchemaIntrospectors;

use Illuminate\Database\Connection;
use QuerySentinel\Contracts\SchemaIntrospector;

final class SqliteSchemaIntrospector implements SchemaIntrospector
{
    public function tableExists(Connection $conn, string $table): ?object
    {
        return $conn->selectOne("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]);
    }

    public function listTables(Connection $conn): array
    {
        $rows = $conn->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'");

        return array_map(fn ($r) => (object) ['TABLE_NAME' => $r->name], $rows);
    }

    public function columnExists(Connection $conn, string $dbName, string $table, string $column): ?object
    {
        $cols = $conn->select("PRAGMA table_info('".str_replace("'", "''", $table)."')");
        foreach ($cols as $c) {
            if (($c->name ?? null) === $column) {
                return (object) ['COLUMN_NAME' => $column];
            }
        }

        return null;
    }

    public function listColumns(Connection $conn, string $dbName, string $table): array
    {
        $rows = $conn->select("PRAGMA table_info('".str_replace("'", "''", $table)."')");

        return array_map(fn ($r) => (object) ['COLUMN_NAME' => $r->name], $rows);
    }
}
