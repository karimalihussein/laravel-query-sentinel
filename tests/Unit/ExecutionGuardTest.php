<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QuerySentinel\Exceptions\UnsafeQueryException;
use QuerySentinel\Support\ExecutionGuard;

final class ExecutionGuardTest extends TestCase
{
    private ExecutionGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new ExecutionGuard;
    }

    // ── Allowed queries ────────────────────────────────────────────

    public function test_allows_simple_select(): void
    {
        $this->guard->validate('SELECT * FROM users');
        $this->assertTrue($this->guard->isSafe('SELECT * FROM users'));
    }

    public function test_allows_select_with_subquery(): void
    {
        $sql = 'SELECT * FROM users WHERE id IN (SELECT user_id FROM orders)';
        $this->guard->validate($sql);
        $this->assertTrue($this->guard->isSafe($sql));
    }

    public function test_allows_cte_with_select(): void
    {
        $sql = 'WITH active AS (SELECT * FROM users WHERE active = 1) SELECT * FROM active';
        $this->guard->validate($sql);
        $this->assertTrue($this->guard->isSafe($sql));
    }

    public function test_allows_explain(): void
    {
        $this->guard->validate('EXPLAIN SELECT * FROM users');
        $this->assertTrue($this->guard->isSafe('EXPLAIN SELECT * FROM users'));
    }

    public function test_allows_show_statements(): void
    {
        $this->guard->validate('SHOW CREATE TABLE users');
        $this->assertTrue($this->guard->isSafe('SHOW CREATE TABLE users'));
    }

    public function test_allows_describe(): void
    {
        $this->guard->validate('DESCRIBE users');
        $this->assertTrue($this->guard->isSafe('DESCRIBE users'));
    }

    // ── Blocked queries ────────────────────────────────────────────

    public function test_blocks_insert(): void
    {
        $this->expectException(UnsafeQueryException::class);
        $this->guard->validate('INSERT INTO users (name) VALUES ("test")');
    }

    public function test_blocks_update(): void
    {
        $this->expectException(UnsafeQueryException::class);
        $this->guard->validate('UPDATE users SET name = "test"');
    }

    public function test_blocks_delete(): void
    {
        $this->expectException(UnsafeQueryException::class);
        $this->guard->validate('DELETE FROM users WHERE id = 1');
    }

    public function test_blocks_drop(): void
    {
        $this->expectException(UnsafeQueryException::class);
        $this->guard->validate('DROP TABLE users');
    }

    public function test_blocks_alter(): void
    {
        $this->expectException(UnsafeQueryException::class);
        $this->guard->validate('ALTER TABLE users ADD COLUMN email VARCHAR(255)');
    }

    public function test_blocks_truncate(): void
    {
        $this->expectException(UnsafeQueryException::class);
        $this->guard->validate('TRUNCATE TABLE users');
    }

    public function test_blocks_create(): void
    {
        $this->expectException(UnsafeQueryException::class);
        $this->guard->validate('CREATE TABLE test (id INT)');
    }

    public function test_blocks_grant(): void
    {
        $this->expectException(UnsafeQueryException::class);
        $this->guard->validate('GRANT ALL ON *.* TO "user"');
    }

    public function test_blocks_empty_query(): void
    {
        $this->expectException(UnsafeQueryException::class);
        $this->guard->validate('');
    }

    public function test_blocks_whitespace_only(): void
    {
        $this->expectException(UnsafeQueryException::class);
        $this->guard->validate('   ');
    }

    // ── Case insensitivity ─────────────────────────────────────────

    public function test_blocks_case_insensitive(): void
    {
        $this->assertFalse($this->guard->isSafe('insert into users values (1)'));
        $this->assertFalse($this->guard->isSafe('INSERT INTO users VALUES (1)'));
        $this->assertFalse($this->guard->isSafe('Insert Into users Values (1)'));
    }

    public function test_allows_case_insensitive_select(): void
    {
        $this->assertTrue($this->guard->isSafe('select * from users'));
        $this->assertTrue($this->guard->isSafe('SELECT * FROM users'));
        $this->assertTrue($this->guard->isSafe('Select * From users'));
    }

    // ── isSelect ───────────────────────────────────────────────────

    public function test_is_select_true_for_select(): void
    {
        $this->assertTrue($this->guard->isSelect('SELECT * FROM users'));
    }

    public function test_is_select_true_for_cte(): void
    {
        $this->assertTrue($this->guard->isSelect('WITH cte AS (SELECT 1) SELECT * FROM cte'));
    }

    public function test_is_select_false_for_insert(): void
    {
        $this->assertFalse($this->guard->isSelect('INSERT INTO users VALUES (1)'));
    }

    // ── isSafe ─────────────────────────────────────────────────────

    public function test_is_safe_returns_false_for_destructive(): void
    {
        $this->assertFalse($this->guard->isSafe('DELETE FROM users'));
    }

    public function test_is_safe_returns_true_for_select(): void
    {
        $this->assertTrue($this->guard->isSafe('SELECT 1'));
    }
}
