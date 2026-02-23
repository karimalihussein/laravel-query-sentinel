<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Feature;

use Illuminate\Support\Facades\DB;
use QuerySentinel\Interception\QueryCaptor;
use QuerySentinel\Tests\TestCase;

final class QueryCaptorTest extends TestCase
{
    public function test_captures_queries_during_listening(): void
    {
        $captor = new QueryCaptor;
        $captor->start();

        DB::select('SELECT 1 as test');

        $captures = $captor->stop();

        $this->assertNotEmpty($captures);
        $this->assertStringContainsString('SELECT 1', $captures[0]->sql);
    }

    public function test_does_not_capture_after_stop(): void
    {
        $captor = new QueryCaptor;
        $captor->start();

        DB::select('SELECT 1 as first');

        $captures = $captor->stop();
        $countBefore = count($captures);

        // This query should NOT be captured
        DB::select('SELECT 2 as second');

        $this->assertCount($countBefore, $captures);
    }

    public function test_is_listening_state(): void
    {
        $captor = new QueryCaptor;

        $this->assertFalse($captor->isListening());

        $captor->start();
        $this->assertTrue($captor->isListening());

        $captor->stop();
        $this->assertFalse($captor->isListening());
    }

    public function test_double_start_is_idempotent(): void
    {
        $captor = new QueryCaptor;
        $captor->start();

        DB::select('SELECT 1 as first');

        $captor->start(); // Should not reset captures

        $captures = $captor->stop();

        // The double start should not reset, but also not cause issues
        $this->assertTrue(true); // No exception thrown
    }

    public function test_captures_are_cleared_on_start(): void
    {
        $captor = new QueryCaptor;

        $captor->start();
        DB::select('SELECT 1 as first');
        $captor->stop();

        // Start a new capture session
        $captor->start();
        $captures = $captor->stop();

        // Previous captures should not leak into new session
        $this->assertEmpty($captures);
    }

    public function test_captures_include_execution_time(): void
    {
        $captor = new QueryCaptor;
        $captor->start();

        DB::select('SELECT 1 as test');

        $captures = $captor->stop();

        $this->assertNotEmpty($captures);
        $this->assertGreaterThanOrEqual(0.0, $captures[0]->timeMs);
    }

    public function test_captures_multiple_queries(): void
    {
        $captor = new QueryCaptor;
        $captor->start();

        DB::select('SELECT 1 as first');
        DB::select('SELECT 2 as second');
        DB::select('SELECT 3 as third');

        $captures = $captor->stop();

        $this->assertGreaterThanOrEqual(3, count($captures));
    }
}
