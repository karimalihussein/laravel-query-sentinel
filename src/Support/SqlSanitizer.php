<?php

declare(strict_types=1);

namespace QuerySentinel\Support;

/**
 * Framework-agnostic SQL sanitizer.
 *
 * Cleans raw SQL input before analysis: strips comments,
 * removes trailing semicolons, normalizes whitespace.
 * Does NOT validate safety — that's ExecutionGuard's job.
 */
final class SqlSanitizer
{
    /**
     * Sanitize a SQL string for safe analysis.
     */
    public function sanitize(string $sql): string
    {
        $sql = $this->stripComments($sql);
        $sql = $this->removeTrailingSemicolons($sql);
        $sql = $this->normalizeWhitespace($sql);

        return $sql;
    }

    /**
     * Remove SQL comments that could hide destructive operations.
     *
     * Handles:
     *  - Single-line comments: -- comment
     *  - Hash comments: # comment
     *  - Multi-line comments: /* comment * /
     *
     * Preserves optimizer hints: /*+ hint * /
     */
    private function stripComments(string $sql): string
    {
        // Remove multi-line comments (but preserve optimizer hints /*+ ... */)
        $sql = (string) preg_replace('/\/\*(?!\+).*?\*\//s', '', $sql);

        // Remove single-line -- comments
        $sql = (string) preg_replace('/--[^\n]*$/m', '', $sql);

        // Remove single-line # comments
        $sql = (string) preg_replace('/#[^\n]*$/m', '', $sql);

        return $sql;
    }

    /**
     * Remove trailing semicolons to prevent multi-statement execution.
     */
    private function removeTrailingSemicolons(string $sql): string
    {
        return rtrim(trim($sql), ';');
    }

    /**
     * Collapse multiple whitespace characters into single spaces.
     */
    private function normalizeWhitespace(string $sql): string
    {
        $sql = (string) preg_replace('/\s+/', ' ', $sql);

        return trim($sql);
    }
}
