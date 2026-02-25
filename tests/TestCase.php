<?php

declare(strict_types=1);

namespace QuerySentinel\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as BaseTestCase;
use QuerySentinel\QueryDiagnosticsServiceProvider;

abstract class TestCase extends BaseTestCase
{
    /** Whether test schema has been bootstrapped this run (avoid repeated work). */
    private static bool $testSchemaBootstrapped = false;

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
            $app['config']->set('query-diagnostics.driver', 'sqlite');
            $app['config']->set('database.default', 'sqlite');
            $app['config']->set('database.connections.sqlite', [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootstrapTestSchemaWhenNeeded();
    }

    /**
     * Bootstrap minimal schema on MySQL/PostgreSQL so feature tests have required tables.
     * Uses Laravel Schema::create() for driver-agnostic, CI-safe behavior. No-op for SQLite.
     * Runs once per process (static flag) so it does not pollute or slow every test.
     */
    private function bootstrapTestSchemaWhenNeeded(): void
    {
        $connection = config('database.default');

        if ($connection === 'sqlite') {
            return;
        }

        if (self::$testSchemaBootstrapped) {
            return;
        }

        try {
            if (env('QUERY_SENTINEL_USE_TEST_MIGRATIONS', false)) {
                $this->runTestMigrations();
            } else {
                $this->createTestSchemaViaSchemaBuilder();
            }
            self::$testSchemaBootstrapped = true;
        } catch (\Throwable) {
            // Ignore if DB not available (e.g. unit tests without DB)
        }
    }

    /**
     * Approach 1: Create required tables using Schema::create().
     * Portable across MySQL and PostgreSQL; no raw SQL; runs only when tables are missing.
     */
    private function createTestSchemaViaSchemaBuilder(): void
    {
        $conn = config('database.default');
        if (Schema::connection($conn)->hasTable('users')) {
            return;
        }

        Schema::connection($conn)->create('users', function ($table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });

        Schema::connection($conn)->create('posts', function ($table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('title');
            $table->text('body')->nullable();
            $table->timestamps();
        });

        Schema::connection($conn)->create('orders', function ($table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('status', 32)->default('pending');
            $table->timestamps();
        });
    }

    /**
     * Approach 2: Run test-only migrations from tests/database/migrations.
     * Set QUERY_SENTINEL_USE_TEST_MIGRATIONS=1 to use this instead of Schema::create().
     * Migrations are in tests/database/migrations/ and are MySQL/PostgreSQL portable.
     */
    protected function runTestMigrations(): void
    {
        $connection = config('database.default');
        if ($connection === 'sqlite') {
            return;
        }

        $migrationsPath = realpath(__DIR__.'/database/migrations');
        if ($migrationsPath === false || ! is_dir($migrationsPath)) {
            return;
        }

        $this->loadMigrationsFrom($migrationsPath);
        $this->artisan('migrate', ['--force' => true]);
    }

    /**
     * Table name that exists on the current driver (for builder/EXPLAIN tests).
     * - SQLite: sqlite_master (system table)
     * - MySQL/PostgreSQL: users (bootstrapped in setUp)
     */
    protected function getTestTableName(): string
    {
        $connection = config('database.default');

        return $connection === 'sqlite' ? 'sqlite_master' : 'users';
    }
}
