<?php

declare(strict_types=1);

namespace QuerySentinel\Support\SchemaIntrospectors;

use Illuminate\Database\Connection;
use QuerySentinel\Contracts\SchemaIntrospector;

final class MySqlSchemaIntrospector implements SchemaIntrospector
{
    public function tableExists(Connection $conn, string $table): ?object
    {
        $dbName = $conn->getDatabaseName();

        return $conn->selectOne(
            'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$dbName, $table]
        );
    }

    public function listTables(Connection $conn): array
    {
        $dbName = $conn->getDatabaseName();

        return $conn->select('SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ?', [$dbName]);
    }

    public function columnExists(Connection $conn, string $dbName, string $table, string $column): ?object
    {
        return $conn->selectOne(
            'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$dbName, $table, $column]
        );
    }

    public function listColumns(Connection $conn, string $dbName, string $table): array
    {
        return $conn->select('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$dbName, $table]);
    }
}
