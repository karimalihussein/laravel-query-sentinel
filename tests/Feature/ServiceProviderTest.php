<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Feature;

use QuerySentinel\Contracts\DriverInterface;
use QuerySentinel\Contracts\PlanParserInterface;
use QuerySentinel\Contracts\RuleRegistryInterface;
use QuerySentinel\Contracts\ScoringEngineInterface;
use QuerySentinel\Diagnostics\QueryDiagnostics;
use QuerySentinel\Parsers\ExplainPlanParser;
use QuerySentinel\Rules\RuleRegistry;
use QuerySentinel\Scoring\DefaultScoringEngine;
use QuerySentinel\Tests\TestCase;

final class ServiceProviderTest extends TestCase
{
    public function test_driver_interface_is_bound(): void
    {
        $driver = $this->app->make(DriverInterface::class);

        $this->assertInstanceOf(DriverInterface::class, $driver);
    }

    public function test_plan_parser_interface_is_bound(): void
    {
        $parser = $this->app->make(PlanParserInterface::class);

        $this->assertInstanceOf(ExplainPlanParser::class, $parser);
    }

    public function test_scoring_engine_interface_is_bound(): void
    {
        $engine = $this->app->make(ScoringEngineInterface::class);

        $this->assertInstanceOf(DefaultScoringEngine::class, $engine);
    }

    public function test_rule_registry_interface_is_bound(): void
    {
        $registry = $this->app->make(RuleRegistryInterface::class);

        $this->assertInstanceOf(RuleRegistry::class, $registry);
    }

    public function test_query_diagnostics_is_resolvable(): void
    {
        $diagnostics = $this->app->make(QueryDiagnostics::class);

        $this->assertInstanceOf(QueryDiagnostics::class, $diagnostics);
    }

    public function test_config_is_merged(): void
    {
        $config = $this->app['config']->get('query-diagnostics');

        $this->assertIsArray($config);
        $this->assertArrayHasKey('driver', $config);
        $this->assertArrayHasKey('scoring', $config);
        $this->assertArrayHasKey('rules', $config);
        $this->assertArrayHasKey('thresholds', $config);
        $this->assertArrayHasKey('output', $config);
        $this->assertArrayHasKey('ci', $config);
    }

    public function test_default_driver_matches_database_default(): void
    {
        $expected = $this->app['config']->get('query-diagnostics.driver') ?? $this->app['config']->get('database.default');
        $driver = $this->app->make(DriverInterface::class);

        $this->assertSame($expected, $driver->getName());
    }

    public function test_postgres_driver_resolves_from_config(): void
    {
        $this->app['config']->set('query-diagnostics.driver', 'pgsql');

        // Clear the singleton so it re-resolves with new config
        $this->app->forgetInstance(DriverInterface::class);

        $driver = $this->app->make(DriverInterface::class);

        $this->assertSame('pgsql', $driver->getName());
    }
}
