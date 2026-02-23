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
}
