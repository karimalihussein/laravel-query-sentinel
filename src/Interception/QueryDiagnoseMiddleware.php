<?php

declare(strict_types=1);

namespace QuerySentinel\Interception;

use Closure;
use Illuminate\Http\Request;
use QuerySentinel\Attributes\QueryDiagnose;
use QuerySentinel\Core\Engine;
use QuerySentinel\Core\ProfileReport;
use QuerySentinel\Exceptions\PerformanceViolationException;
use QuerySentinel\Logging\ReportLogger;
use QuerySentinel\Support\SamplingGuard;
use QuerySentinel\Support\ThresholdGuard;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP middleware for automatic query profiling of controller methods.
 *
 * Detects #[QueryDiagnose] attribute on the dispatched controller method,
 * captures all queries during execution, analyzes them through the engine,
 * and logs structured performance reports.
 *
 * Flow:
 *   1. Resolve the controller method from the route
 *   2. Check for #[QueryDiagnose] attribute via reflection (cached)
 *   3. Sampling check — skip profiling if not sampled
 *   4. Start passive query capture via QueryCaptor
 *   5. Execute the request (controller runs normally)
 *   6. Stop capture, analyze the slowest query via Engine
 *   7. Threshold check — skip logging if below threshold
 *   8. Log structured report via ReportLogger
 *   9. If failOnCritical — throw PerformanceViolationException
 */
final class QueryDiagnoseMiddleware
{
    /** @var array<string, QueryDiagnose|false> */
    private static array $attributeCache = [];

    public function __construct(
        private readonly Engine $engine,
        private readonly ReportLogger $logger,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        if (! $route) {
            return $next($request);
        }

        // Resolve controller class and method
        $action = $route->getAction('uses');

        if (! is_string($action) || ! str_contains($action, '@')) {
            return $next($request);
        }

        [$class, $method] = explode('@', $action, 2);

        // Check for #[QueryDiagnose] attribute (cached)
        $attribute = $this->resolveAttribute($class, $method);

        if ($attribute === null) {
            return $next($request);
        }

        // Sampling check
        $globalRate = (float) config('query-diagnostics.diagnostics.global_sample_rate', 1.0);

        if (! SamplingGuard::shouldProfile($attribute->sampleRate, $globalRate)) {
            return $next($request);
        }

        // Start passive query capture
        $captor = new QueryCaptor;
        $captor->start();

        // Execute the request normally
        $response = $next($request);

        // Stop capture
        $captures = $captor->stop();

        if (empty($captures)) {
            return $response;
        }

        // Analyze captured queries through engine's profiler analysis pipeline
        $report = $this->analyzeCaptures($captures);

        if ($report === null) {
            return $response;
        }

        // Threshold check
        $globalThreshold = (int) config('query-diagnostics.diagnostics.default_threshold_ms', 0);

        if (! ThresholdGuard::shouldLog($report->cumulativeTimeMs, $attribute->thresholdMs, $globalThreshold)) {
            return $response;
        }

        // Log structured report
        $this->logger->log($report, $class, $method, $attribute->logChannel);

        // Fail on critical if enabled
        if ($attribute->failOnCritical && $report->hasCriticalFindings()) {
            throw PerformanceViolationException::fromReport($report, $class, $method);
        }

        return $response;
    }

    /**
     * Resolve #[QueryDiagnose] attribute from a controller method, with caching.
     */
    private function resolveAttribute(string $class, string $method): ?QueryDiagnose
    {
        $cacheKey = $class.'::'.$method;

        if (array_key_exists($cacheKey, self::$attributeCache)) {
            $cached = self::$attributeCache[$cacheKey];

            return $cached === false ? null : $cached;
        }

        try {
            $reflection = new \ReflectionMethod($class, $method);
            $attributes = $reflection->getAttributes(QueryDiagnose::class);

            if (empty($attributes)) {
                self::$attributeCache[$cacheKey] = false;

                return null;
            }

            $attribute = $attributes[0]->newInstance();
            self::$attributeCache[$cacheKey] = $attribute;

            return $attribute;
        } catch (\ReflectionException) {
            self::$attributeCache[$cacheKey] = false;

            return null;
        }
    }

    /**
     * Analyze captured queries using the engine.
     *
     * Only analyzes the slowest SELECT query to minimize overhead.
     *
     * @param  array<int, \QuerySentinel\Support\QueryCapture>  $captures
     */
    private function analyzeCaptures(array $captures): ?ProfileReport
    {
        $guard = $this->engine->getGuard();
        $sanitizer = $this->engine->getSanitizer();

        $reports = [];
        $skippedQueries = [];

        // Find and analyze only SELECT queries — analyze the slowest one
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

        if (empty($reports) && empty($captures)) {
            return null;
        }

        return ProfileReport::fromCaptures($captures, $reports, $skippedQueries);
    }

    /**
     * Clear the attribute cache (useful in testing).
     */
    public static function clearCache(): void
    {
        self::$attributeCache = [];
    }
}
