<?php

declare(strict_types=1);

namespace QuerySentinel\Interception;

use QuerySentinel\Attributes\QueryDiagnose;
use QuerySentinel\Core\Engine;
use QuerySentinel\Core\ProfileReport;
use QuerySentinel\Exceptions\PerformanceViolationException;
use QuerySentinel\Logging\ReportLogger;
use QuerySentinel\Support\SamplingGuard;
use QuerySentinel\Support\ThresholdGuard;

/**
 * Service proxy that intercepts methods decorated with #[QueryDiagnose].
 *
 * Wraps a target object and intercepts method calls through __call().
 * For methods that have the #[QueryDiagnose] attribute, the interceptor:
 *   1. Checks sampling rate
 *   2. Passively captures all queries during execution
 *   3. Analyzes the slowest captured SELECT query
 *   4. Applies threshold filtering
 *   5. Logs structured performance report
 *   6. Optionally throws on critical findings
 *
 * Non-attributed methods pass through directly with zero overhead.
 *
 * Usage via ContainerProxy::register() — no manual setup needed.
 */
final class MethodInterceptor
{
    /** @var array<string, QueryDiagnose|false> */
    private array $attributeCache = [];

    public function __construct(
        private readonly object $target,
        private readonly Engine $engine,
        private readonly ReportLogger $logger,
    ) {}

    /**
     * Intercept method calls on the target object.
     *
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        $attribute = $this->resolveAttribute($method);

        // No attribute — pass through directly
        if ($attribute === null) {
            return $this->target->{$method}(...$arguments);
        }

        // Sampling check
        $globalRate = (float) config('query-diagnostics.diagnostics.global_sample_rate', 1.0);

        if (! SamplingGuard::shouldProfile($attribute->sampleRate, $globalRate)) {
            return $this->target->{$method}(...$arguments);
        }

        // Start passive query capture
        $captor = new QueryCaptor;
        $captor->start();

        try {
            $result = $this->target->{$method}(...$arguments);
        } finally {
            $captures = $captor->stop();
        }

        $this->processCaptures($captures, $attribute, $method);

        return $result;
    }

    /**
     * Forward property reads to the target object.
     */
    public function __get(string $name): mixed
    {
        return $this->target->{$name};
    }

    /**
     * Forward property writes to the target object.
     */
    public function __set(string $name, mixed $value): void
    {
        $this->target->{$name} = $value;
    }

    /**
     * Forward isset checks to the target object.
     */
    public function __isset(string $name): bool
    {
        return isset($this->target->{$name});
    }

    /**
     * Get the underlying target object.
     */
    public function getTarget(): object
    {
        return $this->target;
    }

    /**
     * Resolve #[QueryDiagnose] attribute from the target's method.
     */
    private function resolveAttribute(string $method): ?QueryDiagnose
    {
        if (array_key_exists($method, $this->attributeCache)) {
            $cached = $this->attributeCache[$method];

            return $cached === false ? null : $cached;
        }

        try {
            $reflection = new \ReflectionMethod($this->target, $method);
            $attributes = $reflection->getAttributes(QueryDiagnose::class);

            if (empty($attributes)) {
                $this->attributeCache[$method] = false;

                return null;
            }

            $attribute = $attributes[0]->newInstance();
            $this->attributeCache[$method] = $attribute;

            return $attribute;
        } catch (\ReflectionException) {
            $this->attributeCache[$method] = false;

            return null;
        }
    }

    /**
     * Process captured queries: analyze, threshold check, log, fail.
     *
     * @param  array<int, \QuerySentinel\Support\QueryCapture>  $captures
     */
    private function processCaptures(array $captures, QueryDiagnose $attribute, string $method): void
    {
        if (empty($captures)) {
            return;
        }

        $guard = $this->engine->getGuard();
        $sanitizer = $this->engine->getSanitizer();

        $reports = [];
        $skippedQueries = [];
        $slowestCapture = null;
        $slowestTime = 0.0;

        foreach ($captures as $capture) {
            $interpolated = $capture->toInterpolatedSql();
            $sanitized = $sanitizer->sanitize($interpolated);

            if (! $guard->isSelect($sanitized) || ! $guard->isSafe($sanitized)) {
                $skippedQueries[] = $sanitized;

                continue;
            }

            if ($capture->timeMs > $slowestTime) {
                $slowestTime = $capture->timeMs;
                $slowestCapture = $capture;
            }
        }

        if ($slowestCapture !== null) {
            try {
                $interpolated = $slowestCapture->toInterpolatedSql();
                $sanitized = $sanitizer->sanitize($interpolated);
                $reports[] = $this->engine->getAnalyzer()->analyze($sanitized, 'profiler');
            } catch (\Throwable) {
                $skippedQueries[] = $slowestCapture->toInterpolatedSql();
            }
        }

        $report = ProfileReport::fromCaptures($captures, $reports, $skippedQueries);

        // Threshold check
        $globalThreshold = (int) config('query-diagnostics.diagnostics.default_threshold_ms', 0);

        if (! ThresholdGuard::shouldLog($report->cumulativeTimeMs, $attribute->thresholdMs, $globalThreshold)) {
            return;
        }

        // Log structured report
        $className = get_class($this->target);
        $this->logger->log($report, $className, $method, $attribute->logChannel);

        // Fail on critical if enabled
        if ($attribute->failOnCritical && $report->hasCriticalFindings()) {
            throw PerformanceViolationException::fromReport($report, $className, $method);
        }
    }
}
