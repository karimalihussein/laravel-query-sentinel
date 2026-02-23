<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QuerySentinel\Support\Result;

final class ResultTest extends TestCase
{
    public function test_result_to_array_returns_all_fields(): void
    {
        $result = new Result(
            sql: 'SELECT 1',
            driver: 'mysql',
            explainRows: [],
            plan: '',
            metrics: [],
            scores: [],
            findings: [],
            executionTimeMs: 0.0,
        );

        $array = $result->toArray();

        $this->assertSame('SELECT 1', $array['sql']);
        $this->assertSame('mysql', $array['driver']);
        $this->assertIsArray($array['explain_rows']);
        $this->assertIsArray($array['metrics']);
        $this->assertIsArray($array['scores']);
        $this->assertIsArray($array['findings']);
        $this->assertSame(0.0, $array['execution_time_ms']);
    }
}
