<?php

declare(strict_types=1);

namespace QuerySentinel;

use Illuminate\Support\ServiceProvider;
use QuerySentinel\Analyzers\AntiPatternAnalyzer;
use QuerySentinel\Analyzers\CardinalityDriftAnalyzer;
use QuerySentinel\Analyzers\ConcurrencyRiskAnalyzer;
use QuerySentinel\Analyzers\HypotheticalIndexAnalyzer;
use QuerySentinel\Analyzers\IndexSynthesisAnalyzer;
use QuerySentinel\Analyzers\MemoryPressureAnalyzer;
use QuerySentinel\Analyzers\MetricsExtractor;
use QuerySentinel\Analyzers\RegressionBaselineAnalyzer;
use QuerySentinel\Analyzers\ScalabilityEstimator;
use QuerySentinel\Analyzers\WorkloadAnalyzer;
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
use QuerySentinel\Scoring\ConfidenceScorer;
use QuerySentinel\Scoring\DefaultScoringEngine;
use QuerySentinel\Support\BaselineStore;
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

        // Deep diagnostic analyzers (existing)
        $this->app->singleton(Analyzers\EnvironmentAnalyzer::class);
        $this->app->singleton(Analyzers\ExecutionProfileAnalyzer::class);
        $this->app->singleton(Analyzers\IndexCardinalityAnalyzer::class);
        $this->app->singleton(Analyzers\JoinAnalyzer::class);
        $this->app->singleton(Analyzers\PlanStabilityAnalyzer::class);
        $this->app->singleton(Analyzers\RegressionSafetyAnalyzer::class);

        // Phase 1: Cardinality Drift
        $this->app->singleton(CardinalityDriftAnalyzer::class, function ($app) {
            $config = $app['config']->get('query-diagnostics.cardinality_drift', []);

            return new CardinalityDriftAnalyzer(
                warningThreshold: $config['warning_threshold'] ?? 0.5,
                criticalThreshold: $config['critical_threshold'] ?? 0.9,
            );
        });

        // Phase 3: Anti-Pattern Analyzer
        $this->app->singleton(AntiPatternAnalyzer::class, function ($app) {
            $config = $app['config']->get('query-diagnostics.anti_patterns', []);

            return new AntiPatternAnalyzer(
                orChainThreshold: $config['or_chain_threshold'] ?? 3,
                missingLimitRowThreshold: $config['missing_limit_row_threshold'] ?? 10000,
            );
        });

        // Phase 4: Index Synthesis
        $this->app->singleton(IndexSynthesisAnalyzer::class, function ($app) {
            $config = $app['config']->get('query-diagnostics.index_synthesis', []);

            return new IndexSynthesisAnalyzer(
                maxRecommendations: $config['max_recommendations'] ?? 3,
                maxColumnsPerIndex: $config['max_columns_per_index'] ?? 5,
            );
        });

        // Phase 5: Confidence Scorer
        $this->app->singleton(ConfidenceScorer::class);

        // Phase 6: Concurrency Risk
        $this->app->singleton(ConcurrencyRiskAnalyzer::class);

        // Phase 7: Memory Pressure
        $this->app->singleton(MemoryPressureAnalyzer::class, function ($app) {
            $config = $app['config']->get('query-diagnostics.memory_pressure', []);

            return new MemoryPressureAnalyzer(
                highThresholdBytes: $config['high_threshold_bytes'] ?? 268435456,
                moderateThresholdBytes: $config['moderate_threshold_bytes'] ?? 67108864,
                concurrentSessions: $config['concurrent_sessions'] ?? 10,
            );
        });

        // Phase 9: Regression Baselines
        $this->app->singleton(BaselineStore::class, function ($app) {
            $config = $app['config']->get('query-diagnostics.regression', []);
            $path = $config['storage_path'] ?? storage_path('query-sentinel/baselines');

            return new BaselineStore($path);
        });

        $this->app->singleton(RegressionBaselineAnalyzer::class, function ($app) {
            $config = $app['config']->get('query-diagnostics.regression', []);

            return new RegressionBaselineAnalyzer(
                store: $app->make(BaselineStore::class),
                maxHistory: $config['max_history'] ?? 10,
                scoreWarningThreshold: (float) ($config['score_warning_threshold'] ?? 10),
                scoreCriticalThreshold: (float) ($config['score_critical_threshold'] ?? 25),
                timeWarningThreshold: (float) ($config['time_warning_threshold'] ?? 50),
                timeCriticalThreshold: (float) ($config['time_critical_threshold'] ?? 200),
                absoluteTimeThreshold: (float) ($config['absolute_time_threshold'] ?? 5),
                absoluteScoreThreshold: (float) ($config['absolute_score_threshold'] ?? 5),
                noiseFloorMs: (float) ($config['noise_floor_ms'] ?? 3),
                minimumMeasurableMs: (float) ($config['minimum_measurable_ms'] ?? 5),
            );
        });

        // Workload-Level Modeling
        $this->app->singleton(WorkloadAnalyzer::class, function ($app) {
            $config = $app['config']->get('query-diagnostics.workload', []);

            return new WorkloadAnalyzer(
                store: $app->make(BaselineStore::class),
                frequencyThreshold: $config['frequency_threshold'] ?? 10,
                exportRowThreshold: $config['export_row_threshold'] ?? 100_000,
                networkBytesThreshold: $config['network_bytes_threshold'] ?? 52428800,
            );
        });

        // Phase 10: Hypothetical Index Simulation
        $this->app->singleton(HypotheticalIndexAnalyzer::class, function ($app) {
            $config = $app['config']->get('query-diagnostics.hypothetical_index', []);

            return new HypotheticalIndexAnalyzer(
                maxSimulations: $config['max_simulations'] ?? 3,
                timeoutSeconds: $config['timeout_seconds'] ?? 5,
                allowedEnvironments: $config['allowed_environments'] ?? ['local', 'testing'],
            );
        });
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
                connection: $app['config']->get('query-diagnostics.connection'),
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
                cardinalityDriftAnalyzer: $app->make(CardinalityDriftAnalyzer::class),
                antiPatternAnalyzer: $app->make(AntiPatternAnalyzer::class),
                indexSynthesisAnalyzer: $app->make(IndexSynthesisAnalyzer::class),
                confidenceScorer: $app->make(ConfidenceScorer::class),
                concurrencyRiskAnalyzer: $app->make(ConcurrencyRiskAnalyzer::class),
                memoryPressureAnalyzer: $app->make(MemoryPressureAnalyzer::class),
                regressionBaselineAnalyzer: $app['config']->get('query-diagnostics.regression.enabled', true)
                    ? $app->make(RegressionBaselineAnalyzer::class)
                    : null,
                hypotheticalIndexAnalyzer: $app['config']->get('query-diagnostics.hypothetical_index.enabled', false)
                    ? $app->make(HypotheticalIndexAnalyzer::class)
                    : null,
                driver: $app->make(DriverInterface::class),
                workloadAnalyzer: $app['config']->get('query-diagnostics.workload.enabled', true)
                    ? $app->make(WorkloadAnalyzer::class)
                    : null,
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
