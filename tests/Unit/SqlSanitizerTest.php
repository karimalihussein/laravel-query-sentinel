<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QuerySentinel\Support\SqlSanitizer;

final class SqlSanitizerTest extends TestCase
{
    private SqlSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new SqlSanitizer;
    }

    public function test_trims_whitespace(): void
    {
        $this->assertSame('SELECT 1', $this->sanitizer->sanitize('  SELECT 1  '));
    }

    public function test_removes_trailing_semicolons(): void
    {
        $this->assertSame('SELECT 1', $this->sanitizer->sanitize('SELECT 1;'));
    }

    public function test_removes_multiple_semicolons(): void
    {
        $this->assertSame('SELECT 1', $this->sanitizer->sanitize('SELECT 1;;;'));
    }

    public function test_strips_single_line_comments(): void
    {
        $sql = "SELECT * FROM users -- get all users\nWHERE active = 1";
        $result = $this->sanitizer->sanitize($sql);

        $this->assertSame('SELECT * FROM users WHERE active = 1', $result);
    }

    public function test_strips_hash_comments(): void
    {
        $sql = "SELECT * FROM users # get all users\nWHERE active = 1";
        $result = $this->sanitizer->sanitize($sql);

        $this->assertSame('SELECT * FROM users WHERE active = 1', $result);
    }

    public function test_strips_multiline_comments(): void
    {
        $sql = 'SELECT /* this is a comment */ * FROM users';
        $result = $this->sanitizer->sanitize($sql);

        $this->assertSame('SELECT * FROM users', $result);
    }

    public function test_preserves_optimizer_hints(): void
    {
        $sql = 'SELECT /*+ NO_INDEX(users) */ * FROM users';
        $result = $this->sanitizer->sanitize($sql);

        $this->assertSame('SELECT /*+ NO_INDEX(users) */ * FROM users', $result);
    }

    public function test_normalizes_whitespace(): void
    {
        $sql = "SELECT   *\n  FROM\n  users\n  WHERE   id = 1";
        $result = $this->sanitizer->sanitize($sql);

        $this->assertSame('SELECT * FROM users WHERE id = 1', $result);
    }

    public function test_handles_empty_string(): void
    {
        $this->assertSame('', $this->sanitizer->sanitize(''));
    }

    public function test_handles_comment_only_query(): void
    {
        $this->assertSame('', $this->sanitizer->sanitize('-- just a comment'));
    }

    public function test_combined_sanitization(): void
    {
        $sql = "  SELECT * FROM users -- comment\n  WHERE id = 1; ";
        $result = $this->sanitizer->sanitize($sql);

        $this->assertSame('SELECT * FROM users WHERE id = 1', $result);
    }
}
