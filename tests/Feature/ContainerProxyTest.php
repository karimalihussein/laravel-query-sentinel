<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Feature;

use Illuminate\Support\Facades\Log;
use QuerySentinel\Attributes\QueryDiagnose;
use QuerySentinel\Interception\ContainerProxy;
use QuerySentinel\Interception\MethodInterceptor;
use QuerySentinel\Tests\TestCase;

final class ContainerProxyTest extends TestCase
{
    public function test_registers_proxy_for_service_class(): void
    {
        // Bind a concrete class
        $this->app->bind(ProxyTestService::class);

        // Register proxy
        ContainerProxy::register($this->app, [ProxyTestService::class]);

        // Resolve from container — should be wrapped in MethodInterceptor
        $service = $this->app->make(ProxyTestService::class);

        $this->assertInstanceOf(MethodInterceptor::class, $service);
    }

    public function test_proxied_service_method_calls_work(): void
    {
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('log')->zeroOrMoreTimes();

        $this->app->bind(ProxyTestService::class);
        ContainerProxy::register($this->app, [ProxyTestService::class]);

        $service = $this->app->make(ProxyTestService::class);
        $result = $service->greet('world');

        $this->assertSame('hello world', $result);
    }

    public function test_skips_non_existent_classes(): void
    {
        // Should not throw for non-existent classes
        ContainerProxy::register($this->app, ['App\NonExistent\FakeClass']);

        $this->assertTrue(true); // No exception
    }

    public function test_proxied_attributed_method_is_profiled(): void
    {
        Log::shouldReceive('channel')
            ->with('performance')
            ->atLeast()
            ->once()
            ->andReturnSelf();

        Log::shouldReceive('log')
            ->atLeast()
            ->once()
            ->withArgs(function (string $level, string $message, array $context) {
                return $message === 'QuerySentinel profile'
                    && $context['type'] === 'query_sentinel_profile';
            });

        $this->app->bind(ProxyTestService::class);
        ContainerProxy::register($this->app, [ProxyTestService::class]);

        $service = $this->app->make(ProxyTestService::class);
        $service->profiledQuery();
    }

    public function test_config_based_registration(): void
    {
        $this->app['config']->set('query-diagnostics.diagnostics.classes', [
            ProxyTestService::class,
        ]);

        $this->app->bind(ProxyTestService::class);
        ContainerProxy::register(
            $this->app,
            $this->app['config']->get('query-diagnostics.diagnostics.classes', []),
        );

        $service = $this->app->make(ProxyTestService::class);

        $this->assertInstanceOf(MethodInterceptor::class, $service);
    }
}

class ProxyTestService
{
    public function greet(string $name): string
    {
        return 'hello '.$name;
    }

    #[QueryDiagnose]
    public function profiledQuery(): string
    {
        \Illuminate\Support\Facades\DB::select('SELECT 1 as test');

        return 'done';
    }
}
