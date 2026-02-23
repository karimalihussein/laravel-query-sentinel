<?php

declare(strict_types=1);

namespace QuerySentinel\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use QuerySentinel\QueryDiagnosticsServiceProvider;

abstract class TestCase extends BaseTestCase
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            QueryDiagnosticsServiceProvider::class,
        ];
    }

    /**
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return [
            'QueryDiagnostics' => \QuerySentinel\Facades\QueryDiagnostics::class,
        ];
    }

    /**
     * Use SQLite in-memory so tests run without MySQL/Postgres in CI.
     */
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
    }
}
