<?php

declare(strict_types=1);

namespace QuerySentinel\Support;

/**
 * Pure regex-based SQL column extraction utilities.
 *
 * Extracts WHERE, JOIN, ORDER BY, and SELECT column references from SQL
 * for use by index cardinality analysis and "Explain Why" insights.
 */
final class SqlParser
{
    /**
     * Extract columns referenced in WHERE clauses.
     *
     * @return string[] e.g., ['clients.id', 'status', 'submissions.form_id']
     */
    public static function extractWhereColumns(string $sql): array
    {
        $columns = [];

        if (! preg_match('/\bWHERE\b(.+?)(?:\bORDER\s+BY\b|\bGROUP\s+BY\b|\bHAVING\b|\bLIMIT\b|\bUNION\b|$)/is', $sql, $whereMatch)) {
            return $columns;
        }

        $whereClause = $whereMatch[1];

        // Match table.column or just column before comparison operators
        if (preg_match_all('/(`?\w+`?\.)?`?(\w+)`?\s*(?:=|!=|<>|>=?|<=?|IN\s*\(|IS\s+(?:NOT\s+)?NULL|LIKE|BETWEEN)/i', $whereClause, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $table = trim($match[1], '`.') ?: null;
                $column = trim($match[2], '`');

                $columns[] = $table ? "{$table}.{$column}" : $column;
            }
        }

        return array_unique($columns);
    }

    /**
     * Extract columns used in JOIN ON conditions.
     *
     * @return string[] e.g., ['submissions.client_id', 'clients.id']
     */
    public static function extractJoinColumns(string $sql): array
    {
        $columns = [];

        if (preg_match_all('/\bJOIN\b.+?\bON\b\s*(.+?)(?:\bJOIN\b|\bWHERE\b|\bORDER\b|\bGROUP\b|\bLIMIT\b|\bUNION\b|$)/is', $sql, $onMatches)) {
            foreach ($onMatches[1] as $onClause) {
                if (preg_match_all('/(`?\w+`?\.)?`?(\w+)`?/i', $onClause, $colMatches, PREG_SET_ORDER)) {
                    foreach ($colMatches as $match) {
                        $table = trim($match[1], '`.') ?: null;
                        $column = trim($match[2], '`');

                        // Skip SQL keywords
                        if (in_array(strtoupper($column), ['AND', 'OR', 'ON', 'NULL', 'IS', 'NOT'], true)) {
                            continue;
                        }

                        $columns[] = $table ? "{$table}.{$column}" : $column;
                    }
                }
            }
        }

        return array_unique($columns);
    }

    /**
     * Extract ORDER BY column references.
     *
     * @return string[] e.g., ['clients.created_at DESC', 'id ASC']
     */
    public static function extractOrderByColumns(string $sql): array
    {
        $columns = [];

        if (! preg_match('/\bORDER\s+BY\b\s+(.+?)(?:\bLIMIT\b|\bUNION\b|$)/is', $sql, $match)) {
            return $columns;
        }

        $orderClause = $match[1];
        $parts = preg_split('/\s*,\s*/', trim($orderClause));

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $columns[] = $part;
            }
        }

        return $columns;
    }

    /**
     * Extract columns in the SELECT clause.
     *
     * @return string[]
     */
    public static function extractSelectColumns(string $sql): array
    {
        if (! preg_match('/\bSELECT\b\s+(.+?)\bFROM\b/is', $sql, $match)) {
            return [];
        }

        $selectClause = $match[1];

        // Handle SELECT *
        if (preg_match('/^\s*\*\s*$/', $selectClause)) {
            return ['*'];
        }

        $columns = [];
        $parts = preg_split('/\s*,\s*/', trim($selectClause));

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $columns[] = $part;
            }
        }

        return $columns;
    }

    /**
     * Check if the query uses SELECT *.
     */
    public static function isSelectStar(string $sql): bool
    {
        return (bool) preg_match('/\bSELECT\s+\*/i', $sql);
    }

    /**
     * Detect the primary (FROM) table name.
     */
    public static function detectPrimaryTable(string $sql): string
    {
        if (preg_match('/\bFROM\s+`?(\w+)`?/i', $sql, $match)) {
            return $match[1];
        }

        return 'unknown';
    }

    /**
     * Extract all table names from FROM and JOIN clauses.
     *
     * @return string[] e.g. ['users', 'orders', 'clients']
     */
    public static function extractTables(string $sql): array
    {
        $tables = [];

        if (preg_match('/\bFROM\s+`?(\w+)`?/i', $sql, $m)) {
            $tables[] = $m[1];
        }
        if (preg_match_all('/\b(?:INNER|LEFT|RIGHT|FULL|CROSS|STRAIGHT_)?\s*JOIN\s+`?(\w+)`?/i', $sql, $ms)) {
            foreach ($ms[1] as $t) {
                $tables[] = $t;
            }
        }

        return array_values(array_unique($tables));
    }

    /**
     * Extract table alias map: alias => table name.
     *
     * @return array<string, string> e.g. ['u' => 'users', 'o' => 'orders']
     */
    public static function extractTableAliases(string $sql): array
    {
        $aliases = [];

        if (preg_match('/\bFROM\s+`?(\w+)`?(?:\s+(?:AS\s+)?`?(\w+)`?)?/i', $sql, $m) && isset($m[2]) && $m[2] !== '') {
            $aliases[$m[2]] = $m[1];
        }
        if (preg_match_all('/\b(?:INNER|LEFT|RIGHT|FULL|CROSS|STRAIGHT_)?\s*JOIN\s+`?(\w+)`?(?:\s+(?:AS\s+)?`?(\w+)`?)?/i', $sql, $ms, PREG_SET_ORDER)) {
            foreach ($ms as $m) {
                if (isset($m[2]) && $m[2] !== '') {
                    $aliases[$m[2]] = $m[1];
                }
            }
        }

        return $aliases;
    }

    /**
     * Extract all column references (table.column or column) from WHERE, JOIN ON, SELECT.
     *
     * @return array<int, array{table: string|null, column: string}>
     */
    public static function extractColumnReferences(string $sql): array
    {
        $refs = [];
        $whereCols = self::extractWhereColumns($sql);
        $joinCols = self::extractJoinColumns($sql);

        foreach (array_merge($whereCols, $joinCols) as $col) {
            $parts = explode('.', $col);
            if (count($parts) === 2) {
                $refs[] = ['table' => $parts[0], 'column' => $parts[1]];
            } else {
                $refs[] = ['table' => null, 'column' => $col];
            }
        }

        return $refs;
    }

    /**
     * Extract the WHERE clause from SQL.
     */
    public static function extractWhereClause(string $sql): ?string
    {
        if (preg_match('/\bWHERE\b(.+?)(?:\bORDER\s+BY\b|\bGROUP\s+BY\b|\bHAVING\b|\bLIMIT\b|\bUNION\b|$)/is', $sql, $match)) {
            return trim($match[1]);
        }
        return null;
    }

    /**
     * Detect function calls on columns in WHERE clause (prevents index usage).
     *
     * @return array<int, array{function: string, column: string}>
     */
    public static function detectFunctionsOnColumns(string $sql): array
    {
        $results = [];
        $whereClause = self::extractWhereClause($sql);
        if ($whereClause === null) {
            return $results;
        }

        // Match patterns like UPPER(column), YEAR(date_col), LOWER(name), etc.
        if (preg_match_all('/\b(\w+)\s*\(\s*(`?\w+`?\.)?`?(\w+)`?\s*\)/i', $whereClause, $matches, PREG_SET_ORDER)) {
            $sqlFunctions = ['UPPER', 'LOWER', 'TRIM', 'LTRIM', 'RTRIM', 'YEAR', 'MONTH', 'DAY', 'DATE', 'CAST', 'CONVERT', 'COALESCE', 'IFNULL', 'SUBSTRING', 'SUBSTR', 'LEFT', 'RIGHT', 'LENGTH', 'CHAR_LENGTH', 'CONCAT', 'REPLACE', 'ABS', 'CEIL', 'FLOOR', 'ROUND', 'MD5', 'SHA1', 'SHA2', 'HEX', 'UNHEX', 'INET_ATON', 'INET_NTOA', 'JSON_EXTRACT', 'JSON_UNQUOTE'];

            foreach ($matches as $match) {
                $func = strtoupper($match[1]);
                if (in_array($func, $sqlFunctions, true)) {
                    $results[] = [
                        'function' => $func,
                        'column' => $match[3],
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * Detect correlated subqueries (subquery references outer table).
     */
    public static function detectCorrelatedSubqueries(string $sql): bool
    {
        // Look for subqueries that reference columns with table aliases from outer query
        return (bool) preg_match('/\bWHERE\b.*?\(\s*SELECT\b.*?\bWHERE\b.*?\b\w+\.\w+\s*=\s*\w+\.\w+/is', $sql);
    }

    /**
     * Count OR chains in WHERE clause.
     */
    public static function countOrChains(string $sql): int
    {
        $whereClause = self::extractWhereClause($sql);
        if ($whereClause === null) {
            return 0;
        }

        // Remove subqueries to avoid counting OR inside them
        $cleaned = preg_replace('/\(SELECT\b[^)]*\)/is', '', $whereClause);

        return substr_count(strtoupper($cleaned ?? $whereClause), ' OR ');
    }

    /**
     * Detect if a query is an intentional full dataset retrieval.
     *
     * A full scan is intentional when NO filtering/ordering/grouping clauses exist:
     * no WHERE, no JOIN, no GROUP BY, no HAVING, no ORDER BY.
     * The query simply reads the entire table by design.
     */
    public static function isIntentionalFullScan(string $sql): bool
    {
        if (! preg_match('/^\s*SELECT\b/i', $sql)) {
            return false;
        }

        $hasWhere = (bool) preg_match('/\bWHERE\b/i', $sql);
        $hasJoin = (bool) preg_match('/\bJOIN\b/i', $sql);
        $hasGroupBy = (bool) preg_match('/\bGROUP\s+BY\b/i', $sql);
        $hasHaving = (bool) preg_match('/\bHAVING\b/i', $sql);
        $hasOrderBy = (bool) preg_match('/\bORDER\s+BY\b/i', $sql);

        return ! $hasWhere && ! $hasJoin && ! $hasGroupBy && ! $hasHaving && ! $hasOrderBy;
    }

    /**
     * Detect leading wildcard in LIKE patterns (prevents index usage).
     */
    public static function hasLeadingWildcard(string $sql): bool
    {
        return (bool) preg_match('/\bLIKE\s+[\'"]%/i', $sql);
    }

    /**
     * Detect if the query contains a LIMIT clause.
     */
    public static function hasLimit(string $sql): bool
    {
        return (bool) preg_match('/\bLIMIT\b/i', $sql);
    }

    /**
     * Detect if the query contains an EXISTS subquery.
     */
    public static function hasExists(string $sql): bool
    {
        return (bool) preg_match('/\bEXISTS\s*\(/i', $sql);
    }

    /**
     * Detect aggregation without GROUP BY (single-row result = natural early termination).
     */
    public static function hasAggregationWithoutGroupBy(string $sql): bool
    {
        $hasAgg = (bool) preg_match('/\bSELECT\b[^)]*\b(COUNT|SUM|AVG|MIN|MAX)\s*\(/i', $sql);
        $hasGroupBy = (bool) preg_match('/\bGROUP\s+BY\b/i', $sql);

        return $hasAgg && ! $hasGroupBy;
    }
}
