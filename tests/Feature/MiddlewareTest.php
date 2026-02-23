<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use QuerySentinel\Attributes\QueryDiagnose;
use QuerySentinel\Core\Engine;
use QuerySentinel\Interception\QueryDiagnoseMiddleware;
use QuerySentinel\Logging\ReportLogger;
use QuerySentinel\Tests\TestCase;

final class MiddlewareTest extends TestCase
{
    private QueryDiagnoseMiddleware $middleware;

    protected function setUp(): void
    {
        parent::setUp();

        $this->middleware = new QueryDiagnoseMiddleware(
            engine: $this->app->make(Engine::class),
            logger: new ReportLogger,
        );

        QueryDiagnoseMiddleware::clearCache();
    }

    public function test_passes_through_when_no_route(): void
    {
        $request = Request::create('/test', 'GET');
        $called = false;

        $response = $this->middleware->handle($request, function () use (&$called) {
            $called = true;

            return response('ok');
        });

        $this->assertTrue($called);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_passes_through_for_closure_route(): void
    {
        $request = Request::create('/test', 'GET');

        // Create a route with a closure action (no 'uses' string with @)
        $route = new Route('GET', '/test', [function () {
            return 'closure';
        }]);
        $request->setRouteResolver(fn () => $route);

        $called = false;

        $response = $this->middleware->handle($request, function () use (&$called) {
            $called = true;

            return response('ok');
        });

        $this->assertTrue($called);
    }

    public function test_passes_through_when_no_attribute(): void
    {
        $request = $this->createRequestWithRoute(
            MiddlewareTestController::class.'@noAttribute'
        );

        $called = false;
        $response = $this->middleware->handle($request, function () use (&$called) {
            $called = true;

            return response('ok');
        });

        $this->assertTrue($called);
    }

    public function test_profiles_attributed_controller_method(): void
    {
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('log')
            ->once()
            ->withArgs(function (string $level, string $message, array $data) {
                return $message === 'QuerySentinel profile'
                    && $data['class'] === MiddlewareTestController::class
                    && $data['method'] === 'withAttribute';
            });

        $request = $this->createRequestWithRoute(
            MiddlewareTestController::class.'@withAttribute'
        );

        $this->middleware->handle($request, function () {
            \Illuminate\Support\Facades\DB::select('SELECT 1 as test');

            return response('ok');
        });
    }

    public function test_respects_sampling_rate_zero(): void
    {
        Log::shouldReceive('channel')->never();
        Log::shouldReceive('log')->never();

        $request = $this->createRequestWithRoute(
            MiddlewareTestController::class.'@neverSampled'
        );

        $this->middleware->handle($request, function () {
            \Illuminate\Support\Facades\DB::select('SELECT 1 as test');

            return response('ok');
        });
    }

    public function test_respects_threshold(): void
    {
        Log::shouldReceive('channel')->never();
        Log::shouldReceive('log')->never();

        $request = $this->createRequestWithRoute(
            MiddlewareTestController::class.'@highThreshold'
        );

        $this->middleware->handle($request, function () {
            // This will be very fast, below 99999ms threshold
            \Illuminate\Support\Facades\DB::select('SELECT 1 as test');

            return response('ok');
        });
    }

    public function test_response_is_returned_even_with_profiling(): void
    {
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('log')->zeroOrMoreTimes();

        $request = $this->createRequestWithRoute(
            MiddlewareTestController::class.'@withAttribute'
        );

        $response = $this->middleware->handle($request, function () {
            \Illuminate\Support\Facades\DB::select('SELECT 1 as test');

            return response('profiled content', 200);
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('profiled content', $response->getContent());
    }

    public function test_cache_clears(): void
    {
        QueryDiagnoseMiddleware::clearCache();

        // Should not throw
        $this->assertTrue(true);
    }

    private function createRequestWithRoute(string $uses): Request
    {
        $request = Request::create('/test', 'GET');

        $route = new Route('GET', '/test', ['uses' => $uses]);
        $request->setRouteResolver(fn () => $route);

        return $request;
    }
}

class MiddlewareTestController
{
    public function noAttribute(): string
    {
        return 'no attribute';
    }

    #[QueryDiagnose]
    public function withAttribute(): string
    {
        return 'with attribute';
    }

    #[QueryDiagnose(sampleRate: 0.0)]
    public function neverSampled(): string
    {
        return 'never sampled';
    }

    #[QueryDiagnose(thresholdMs: 99999)]
    public function highThreshold(): string
    {
        return 'high threshold';
    }
}
