<?php

declare(strict_types=1);

namespace QuerySentinel;

use Illuminate\Support\ServiceProvider;
use QuerySentinel\Analyzers\MetricsExtractor;
use QuerySentinel\Analyzers\ScalabilityEstimator;
use QuerySentinel\Console\DiagnoseQueryCommand;
use QuerySentinel\Contracts\AnalyzerInterface;
use QuerySentinel\Contracts\DriverInterface;
use QuerySentinel\Contracts\PlanParserInterface;
use QuerySentinel\Contracts\RuleRegistryInterface;
use QuerySentinel\Contracts\ScoringEngineInterface;
use QuerySentinel\Core\Engine;
use QuerySentinel\Core\QueryAnalyzer;
use QuerySentinel\Diagnostics\QueryDiagnostics;
use QuerySentinel\Drivers\MySqlDriver;
use QuerySentinel\Drivers\PostgresDriver;
use QuerySentinel\Interception\ContainerProxy;
use QuerySentinel\Interception\QueryDiagnoseMiddleware;
use QuerySentinel\Logging\ReportLogger;
use QuerySentinel\Parsers\ExplainPlanParser;
use QuerySentinel\Rules\RuleRegistry;
use QuerySentinel\Scoring\DefaultScoringEngine;
use QuerySentinel\Support\ExecutionGuard;
use QuerySentinel\Support\SqlSanitizer;

final class QueryDiagnosticsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/query-diagnostics.php', 'query-diagnostics');

        $this->registerDriver();
        $this->registerParser();
        $this->registerScoringEngine();
        $this->registerRuleRegistry();
        $this->registerAnalyzers();
        $this->registerSupport();
        $this->registerCore();
        $this->registerEngine();
        $this->registerLegacyDiagnostics();
        $this->registerDiagnostics();
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/query-diagnostics.php' => config_path('query-diagnostics.php'),
            ], 'query-sentinel-config');

            $this->commands([
                DiagnoseQueryCommand::class,
            ]);
        }

        $this->bootDiagnostics();
    }

    private function registerDriver(): void
    {
        $this->app->singleton(DriverInterface::class, function ($app) {
            $driver = $app['config']->get('query-diagnostics.driver', 'mysql');
            $connection = $app['config']->get('query-diagnostics.connection');

            return match ($driver) {
                'pgsql', 'postgres' => new PostgresDriver($connection),
                default => new MySqlDriver($connection),
            };
        });
    }

    private function registerParser(): void
    {
        $this->app->singleton(MetricsExtractor::class);

        $this->app->singleton(PlanParserInterface::class, function ($app) {
            return new ExplainPlanParser($app->make(MetricsExtractor::class));
        });
    }

    private function registerScoringEngine(): void
    {
        $this->app->singleton(ScoringEngineInterface::class, function ($app) {
            $weights = $app['config']->get('query-diagnostics.scoring.weights', []);
            $gradeThresholds = $app['config']->get('query-diagnostics.scoring.grade_thresholds', []);

            return new DefaultScoringEngine($weights, $gradeThresholds);
        });
    }

    private function registerRuleRegistry(): void
    {
        $this->app->singleton(RuleRegistryInterface::class, function ($app) {
            $registry = new RuleRegistry;

            $enabledRules = $app['config']->get('query-diagnostics.rules.enabled', []);
            $thresholds = $app['config']->get('query-diagnostics.thresholds', []);

            foreach ($enabledRules as $ruleClass) {
                if (! class_exists($ruleClass)) {
                    continue;
                }

                if ($ruleClass === Rules\DeepNestedLoopRule::class) {
                    $registry->register(new $ruleClass(
                        $thresholds['max_nested_loop_depth'] ?? 4
                    ));
                } else {
                    $registry->register($app->make($ruleClass));
                }
            }

            return $registry;
        });
    }

    private function registerAnalyzers(): void
    {
        $this->app->singleton(ScalabilityEstimator::class);

        // Deep diagnostic analyzers
        $this->app->singleton(Analyzers\EnvironmentAnalyzer::class);
        $this->app->singleton(Analyzers\ExecutionProfileAnalyzer::class);
        $this->app->singleton(Analyzers\IndexCardinalityAnalyzer::class);
        $this->app->singleton(Analyzers\JoinAnalyzer::class);
        $this->app->singleton(Analyzers\PlanStabilityAnalyzer::class);
        $this->app->singleton(Analyzers\RegressionSafetyAnalyzer::class);
    }

    /**
     * Register framework-agnostic support classes.
     */
    private function registerSupport(): void
    {
        $this->app->singleton(ExecutionGuard::class);
        $this->app->singleton(SqlSanitizer::class);
    }

    /**
     * Register Core\QueryAnalyzer as the AnalyzerInterface implementation.
     */
    private function registerCore(): void
    {
        $this->app->singleton(QueryAnalyzer::class, function ($app) {
            return new QueryAnalyzer(
                driver: $app->make(DriverInterface::class),
                parser: $app->make(PlanParserInterface::class),
                scoringEngine: $app->make(ScoringEngineInterface::class),
                ruleRegistry: $app->make(RuleRegistryInterface::class),
                scalabilityEstimator: $app->make(ScalabilityEstimator::class),
            );
        });

        $this->app->singleton(AnalyzerInterface::class, function ($app) {
            return $app->make(QueryAnalyzer::class);
        });
    }

    /**
     * Register the Engine as the unified entry point for all modes.
     */
    private function registerEngine(): void
    {
        $this->app->singleton(Engine::class, function ($app) {
            return new Engine(
                analyzer: $app->make(AnalyzerInterface::class),
                guard: $app->make(ExecutionGuard::class),
                sanitizer: $app->make(SqlSanitizer::class),
            );
        });
    }

    /**
     * Keep the legacy QueryDiagnostics binding for backward compatibility.
     */
    private function registerLegacyDiagnostics(): void
    {
        $this->app->singleton(QueryDiagnostics::class, function ($app) {
            return new QueryDiagnostics(
                driver: $app->make(DriverInterface::class),
                parser: $app->make(PlanParserInterface::class),
                scoringEngine: $app->make(ScoringEngineInterface::class),
                ruleRegistry: $app->make(RuleRegistryInterface::class),
                scalabilityEstimator: $app->make(ScalabilityEstimator::class),
            );
        });
    }

    /**
     * Register attribute-based diagnostics components.
     */
    private function registerDiagnostics(): void
    {
        $this->app->singleton(ReportLogger::class);

        $this->app->singleton(QueryDiagnoseMiddleware::class, function ($app) {
            return new QueryDiagnoseMiddleware(
                engine: $app->make(Engine::class),
                logger: $app->make(ReportLogger::class),
            );
        });
    }

    /**
     * Boot diagnostics: register container proxies for configured classes.
     */
    private function bootDiagnostics(): void
    {
        if (! $this->app['config']->get('query-diagnostics.diagnostics.enabled', true)) {
            return;
        }

        $classes = $this->app['config']->get('query-diagnostics.diagnostics.classes', []);

        if (! empty($classes)) {
            ContainerProxy::register($this->app, $classes);
        }
    }
}
