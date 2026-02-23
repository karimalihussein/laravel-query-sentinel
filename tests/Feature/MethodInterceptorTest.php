<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Feature;

use Illuminate\Support\Facades\Log;
use QuerySentinel\Attributes\QueryDiagnose;
use QuerySentinel\Core\Engine;
use QuerySentinel\Interception\MethodInterceptor;
use QuerySentinel\Logging\ReportLogger;
use QuerySentinel\Tests\TestCase;

final class MethodInterceptorTest extends TestCase
{
    private MethodInterceptor $interceptor;

    private InterceptorTestService $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->target = new InterceptorTestService;
        $this->interceptor = new MethodInterceptor(
            target: $this->target,
            engine: $this->app->make(Engine::class),
            logger: new ReportLogger,
        );
    }

    public function test_passes_through_non_attributed_method(): void
    {
        $result = $this->interceptor->normalMethod('hello');

        $this->assertSame('normal:hello', $result);
    }

    public function test_intercepts_attributed_method(): void
    {
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('log')->once();

        $result = $this->interceptor->profiledMethod();

        $this->assertSame('profiled', $result);
    }

    public function test_forwards_property_read(): void
    {
        $this->assertSame('test-value', $this->interceptor->publicProperty);
    }

    public function test_forwards_property_write(): void
    {
        $this->interceptor->publicProperty = 'new-value';

        $this->assertSame('new-value', $this->target->publicProperty);
    }

    public function test_forwards_isset(): void
    {
        $this->assertTrue(isset($this->interceptor->publicProperty));
    }

    public function test_returns_target_object(): void
    {
        $this->assertSame($this->target, $this->interceptor->getTarget());
    }

    public function test_method_return_value_preserved(): void
    {
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('log')->zeroOrMoreTimes();

        $result = $this->interceptor->profiledWithReturn(42);

        $this->assertSame(84, $result);
    }

    public function test_exception_in_target_propagates(): void
    {
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('log')->zeroOrMoreTimes();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('target error');

        $this->interceptor->profiledWithException();
    }

    public function test_respects_sampling_rate_zero(): void
    {
        Log::shouldReceive('channel')->never();
        Log::shouldReceive('log')->never();

        // This method has sampleRate: 0.0, so should never profile
        $result = $this->interceptor->neverProfiled();

        $this->assertSame('never', $result);
    }
}

/**
 * Test service with attributed and non-attributed methods.
 */
class InterceptorTestService
{
    public string $publicProperty = 'test-value';

    public function normalMethod(string $input): string
    {
        return 'normal:'.$input;
    }

    #[QueryDiagnose]
    public function profiledMethod(): string
    {
        // Run a simple query so there's something to capture
        \Illuminate\Support\Facades\DB::select('SELECT 1 as test');

        return 'profiled';
    }

    #[QueryDiagnose]
    public function profiledWithReturn(int $value): int
    {
        \Illuminate\Support\Facades\DB::select('SELECT 1 as test');

        return $value * 2;
    }

    #[QueryDiagnose(failOnCritical: true)]
    public function profiledWithException(): void
    {
        throw new \RuntimeException('target error');
    }

    #[QueryDiagnose(sampleRate: 0.0)]
    public function neverProfiled(): string
    {
        return 'never';
    }
}
