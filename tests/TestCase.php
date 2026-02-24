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
     * Configure database for tests.
     * - When DB_CONNECTION is mysql or pgsql (CI with real services): use env-driven config.
     * - Otherwise: SQLite in-memory for local runs without MySQL/Postgres.
     */
    protected function getEnvironmentSetUp($app): void
    {
        $connection = env('DB_CONNECTION', 'sqlite');

        if (in_array($connection, ['mysql', 'pgsql'], true)) {
            $app['config']->set('database.default', $connection);
            $defaults = [
                'driver' => $connection,
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', $connection === 'mysql' ? '3306' : '5432'),
                'database' => env('DB_DATABASE', 'testing'),
                'username' => env('DB_USERNAME'),
                'password' => env('DB_PASSWORD', ''),
                'prefix' => '',
            ];
            if ($connection === 'mysql') {
                $defaults['charset'] = env('DB_CHARSET', 'utf8mb4');
                $defaults['collation'] = env('DB_COLLATION', 'utf8mb4_unicode_ci');
            } else {
                $defaults['charset'] = env('DB_CHARSET', 'utf8');
            }
            $app['config']->set("database.connections.{$connection}", $defaults);
            $app['config']->set('query-diagnostics.driver', env('QUERY_SENTINEL_DRIVER', $connection));
        } else {
            $app['config']->set('query-diagnostics.validation.strict', false);
            $app['config']->set('database.default', 'sqlite');
            $app['config']->set('database.connections.sqlite', [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]);
        }
    }
}
