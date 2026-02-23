<?php

declare(strict_types=1);

namespace QuerySentinel\Adapters;

use QuerySentinel\Contracts\ProfilerInterface;
use QuerySentinel\Core\ProfileReport;

/**
 * Class method profiler adapter.
 *
 * Resolves a class from the Laravel container, invokes the specified method,
 * and profiles all database queries executed during the call.
 *
 * Delegates to ProfilerAdapter for the actual query capture and analysis.
 */
final class ClassMethodAdapter
{
    public function __construct(
        private readonly ProfilerInterface $profilerAdapter,
    ) {}

    /**
     * Profile a class method invocation.
     *
     * Resolves the class from the Laravel container (supporting dependency injection),
     * then calls the method with provided arguments while profiling all queries.
     *
     * @param  array<int, mixed>  $arguments
     */
    public function profile(string $class, string $method, array $arguments = []): ProfileReport
    {
        $this->validateCallable($class, $method);

        return $this->profilerAdapter->profile(function () use ($class, $method, $arguments) {
            $instance = app($class);
            $instance->{$method}(...$arguments);
        });
    }

    /**
     * Validate the class and method are callable before profiling.
     */
    private function validateCallable(string $class, string $method): void
    {
        if (! class_exists($class) && ! app()->bound($class)) {
            throw new \InvalidArgumentException(sprintf(
                'Class [%s] does not exist and is not bound in the container.',
                $class,
            ));
        }

        // Method check is deferred to runtime since the class may use __call
    }
}
