<?php

declare(strict_types=1);

namespace QuerySentinel\Support;

use QuerySentinel\Exceptions\UnsafeQueryException;

/**
 * Framework-agnostic SQL safety guard.
 *
 * Validates that only safe, read-only SQL statements are analyzed.
 * Blocks INSERT, UPDATE, DELETE, DROP, ALTER, TRUNCATE, and other
 * destructive operations.
 */
final class ExecutionGuard
{
    /**
     * Destructive keywords that are NEVER allowed, even inside subqueries.
     *
     * @var array<int, string>
     */
    private const DESTRUCTIVE_KEYWORDS = [
        'INSERT',
        'UPDATE',
        'DELETE',
        'DROP',
        'ALTER',
        'TRUNCATE',
        'CREATE',
        'RENAME',
        'REPLACE',
        'GRANT',
        'REVOKE',
        'LOCK',
        'UNLOCK',
        'CALL',
        'LOAD',
    ];

    /**
     * Top-level statement starters that ARE allowed.
     *
     * @var array<int, string>
     */
    private const ALLOWED_STARTERS = [
        'SELECT',
        'EXPLAIN',
        'WITH',   // CTEs: WITH ... SELECT
        'SHOW',   // SHOW CREATE TABLE, etc.
        'DESC',   // DESC(RIBE) table
        'DESCRIBE',
    ];

    /**
     * Inherently read-only starters that skip destructive keyword scanning.
     *
     * SHOW, EXPLAIN, DESC are read-only by definition — keywords like
     * CREATE inside "SHOW CREATE TABLE" are not destructive.
     *
     * @var array<int, string>
     */
    private const READONLY_STARTERS = [
        'SHOW',
        'EXPLAIN',
        'DESC',
        'DESCRIBE',
    ];

    /**
     * Validate SQL is safe for analysis. Throws on unsafe queries.
     *
     * @throws UnsafeQueryException
     */
    public function validate(string $sql): void
    {
        $normalized = strtoupper(trim($sql));

        if ($normalized === '') {
            throw UnsafeQueryException::emptyQuery();
        }

        // Verify the statement starts with an allowed keyword
        $starterMatch = null;

        foreach (self::ALLOWED_STARTERS as $starter) {
            if (str_starts_with($normalized, $starter)) {
                $starterMatch = $starter;

                break;
            }
        }

        if ($starterMatch === null) {
            throw UnsafeQueryException::notAllowed($sql);
        }

        // Read-only starters (SHOW, EXPLAIN, DESC) skip destructive scanning
        // because keywords like CREATE in "SHOW CREATE TABLE" are not destructive
        if (in_array($starterMatch, self::READONLY_STARTERS, true)) {
            return;
        }

        // For SELECT/WITH, scan for destructive keywords in the body
        foreach (self::DESTRUCTIVE_KEYWORDS as $keyword) {
            if (preg_match('/\b'.$keyword.'\b/i', $normalized)) {
                throw UnsafeQueryException::destructiveQuery($keyword);
            }
        }
    }

    /**
     * Check if SQL is safe without throwing.
     */
    public function isSafe(string $sql): bool
    {
        try {
            $this->validate($sql);

            return true;
        } catch (UnsafeQueryException) {
            return false;
        }
    }

    /**
     * Check if SQL is a SELECT statement (including CTEs).
     */
    public function isSelect(string $sql): bool
    {
        $normalized = strtoupper(trim($sql));

        return str_starts_with($normalized, 'SELECT')
            || str_starts_with($normalized, 'WITH');
    }
}
