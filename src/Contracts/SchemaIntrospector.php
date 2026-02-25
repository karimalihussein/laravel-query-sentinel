<?php

declare(strict_types=1);

namespace QuerySentinel\Contracts;

use Illuminate\Database\Connection;

interface SchemaIntrospector
{
    public function tableExists(Connection $conn, string $table): ?object;

    /** @return array<int, object> */
    public function listTables(Connection $conn): array;

    public function columnExists(Connection $conn, string $dbName, string $table, string $column): ?object;

    /** @return array<int, object> */
    public function listColumns(Connection $conn, string $dbName, string $table): array;
}
