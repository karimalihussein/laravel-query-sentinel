<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QuerySentinel\Support\QueryCapture;

final class QueryCaptureTest extends TestCase
{
    public function test_basic_properties(): void
    {
        $capture = new QueryCapture(
            sql: 'SELECT * FROM users WHERE id = ?',
            bindings: [1],
            timeMs: 5.2,
            connection: 'mysql',
        );

        $this->assertSame('SELECT * FROM users WHERE id = ?', $capture->sql);
        $this->assertSame([1], $capture->bindings);
        $this->assertSame(5.2, $capture->timeMs);
        $this->assertSame('mysql', $capture->connection);
    }

    public function test_connection_defaults_to_null(): void
    {
        $capture = new QueryCapture(
            sql: 'SELECT 1',
            bindings: [],
            timeMs: 0.1,
        );

        $this->assertNull($capture->connection);
    }

    public function test_interpolate_sql_with_int_binding(): void
    {
        $capture = new QueryCapture(
            sql: 'SELECT * FROM users WHERE id = ?',
            bindings: [42],
            timeMs: 1.0,
        );

        $this->assertSame('SELECT * FROM users WHERE id = 42', $capture->toInterpolatedSql());
    }

    public function test_interpolate_sql_with_string_binding(): void
    {
        $capture = new QueryCapture(
            sql: 'SELECT * FROM users WHERE name = ?',
            bindings: ['John'],
            timeMs: 1.0,
        );

        $this->assertSame("SELECT * FROM users WHERE name = 'John'", $capture->toInterpolatedSql());
    }

    public function test_interpolate_sql_with_null_binding(): void
    {
        $capture = new QueryCapture(
            sql: 'SELECT * FROM users WHERE deleted_at IS ?',
            bindings: [null],
            timeMs: 1.0,
        );

        $this->assertSame('SELECT * FROM users WHERE deleted_at IS NULL', $capture->toInterpolatedSql());
    }

    public function test_interpolate_sql_with_bool_binding(): void
    {
        $capture = new QueryCapture(
            sql: 'SELECT * FROM users WHERE active = ?',
            bindings: [true],
            timeMs: 1.0,
        );

        $this->assertSame('SELECT * FROM users WHERE active = 1', $capture->toInterpolatedSql());
    }

    public function test_interpolate_sql_with_multiple_bindings(): void
    {
        $capture = new QueryCapture(
            sql: 'SELECT * FROM users WHERE id = ? AND name = ? AND active = ?',
            bindings: [1, 'John', true],
            timeMs: 1.0,
        );

        $result = $capture->toInterpolatedSql();

        $this->assertSame("SELECT * FROM users WHERE id = 1 AND name = 'John' AND active = 1", $result);
    }

    public function test_interpolate_sql_without_bindings(): void
    {
        $capture = new QueryCapture(
            sql: 'SELECT * FROM users',
            bindings: [],
            timeMs: 1.0,
        );

        $this->assertSame('SELECT * FROM users', $capture->toInterpolatedSql());
    }

    public function test_normalized_sql_replaces_strings(): void
    {
        $capture = new QueryCapture(
            sql: "SELECT * FROM users WHERE name = 'John'",
            bindings: [],
            timeMs: 1.0,
        );

        $this->assertSame('SELECT * FROM users WHERE name = ?', $capture->toNormalizedSql());
    }

    public function test_normalized_sql_replaces_numbers(): void
    {
        $capture = new QueryCapture(
            sql: 'SELECT * FROM users WHERE id = 42 AND score > 3.14',
            bindings: [],
            timeMs: 1.0,
        );

        $result = $capture->toNormalizedSql();

        $this->assertSame('SELECT * FROM users WHERE id = ? AND score > ?', $result);
    }

    public function test_normalized_sql_identical_for_different_params(): void
    {
        $capture1 = new QueryCapture(
            sql: 'SELECT * FROM users WHERE id = ?',
            bindings: [1],
            timeMs: 1.0,
        );

        $capture2 = new QueryCapture(
            sql: 'SELECT * FROM users WHERE id = ?',
            bindings: [99],
            timeMs: 2.0,
        );

        $this->assertSame($capture1->toNormalizedSql(), $capture2->toNormalizedSql());
    }

    public function test_to_array(): void
    {
        $capture = new QueryCapture(
            sql: 'SELECT 1',
            bindings: [1],
            timeMs: 0.5,
            connection: 'testing',
        );

        $array = $capture->toArray();

        $this->assertSame('SELECT 1', $array['sql']);
        $this->assertSame([1], $array['bindings']);
        $this->assertSame(0.5, $array['time_ms']);
        $this->assertSame('testing', $array['connection']);
    }
}
