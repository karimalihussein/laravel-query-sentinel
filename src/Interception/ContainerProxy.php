<?php

declare(strict_types=1);

namespace QuerySentinel\Interception;

use Illuminate\Contracts\Foundation\Application;
use QuerySentinel\Core\Engine;
use QuerySentinel\Logging\ReportLogger;

/**
 * Container integration for automatic method interception.
 *
 * Registers MethodInterceptor proxies for configured service classes
 * using Laravel's app->extend(). When a service is resolved from the
 * container, it gets wrapped in a MethodInterceptor that transparently
 * intercepts #[QueryDiagnose]-attributed methods.
 *
 * Usage:
 *   ContainerProxy::register($app, [
 *       \App\Services\LeadQueryService::class,
 *       \App\Services\ReportService::class,
 *   ]);
 *
 * Or via config:
 *   'diagnostics.classes' => [LeadQueryService::class]
 */
final class ContainerProxy
{
    /**
     * Register interception proxies for the given service classes.
     *
     * @param  array<int, class-string>  $classes
     */
    public static function register(Application $app, array $classes): void
    {
        foreach ($classes as $class) {
            if (! class_exists($class)) {
                continue;
            }

            $app->extend($class, function (object $service, Application $app) {
                return new MethodInterceptor(
                    target: $service,
                    engine: $app->make(Engine::class),
                    logger: $app->make(ReportLogger::class),
                );
            });
        }
    }
}
