<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Feature;

use QuerySentinel\Scanner\AttributeScanner;
use QuerySentinel\Tests\TestCase;

final class ScanCommandTest extends TestCase
{
    public function test_command_is_registered(): void
    {
        // Bind scanner with empty paths so it returns nothing
        $this->app->singleton(AttributeScanner::class, fn () => new AttributeScanner([]));

        $this->artisan('query:scan', ['--list' => true])
            ->assertSuccessful();
    }

    public function test_command_shows_no_methods_message_when_empty(): void
    {
        $this->app->singleton(AttributeScanner::class, fn () => new AttributeScanner([]));

        $this->artisan('query:scan', ['--list' => true])
            ->expectsOutputToContain('No methods found')
            ->assertSuccessful();
    }

    public function test_list_mode_outputs_json_when_empty(): void
    {
        $this->app->singleton(AttributeScanner::class, fn () => new AttributeScanner([]));

        $this->artisan('query:scan', ['--list' => true, '--json' => true])
            ->assertSuccessful();
    }

    public function test_scanner_is_resolvable(): void
    {
        $scanner = $this->app->make(AttributeScanner::class);

        $this->assertInstanceOf(AttributeScanner::class, $scanner);
    }

    public function test_list_mode_discovers_fixture_methods(): void
    {
        $fixturesPath = realpath(__DIR__.'/../Unit/Scanner/Fixtures');
        $this->app->singleton(
            AttributeScanner::class,
            fn () => new AttributeScanner([$fixturesPath]),
        );

        $this->artisan('query:scan', ['--list' => true])
            ->expectsOutputToContain('diagnosable method')
            ->assertSuccessful();
    }

    public function test_list_json_mode_returns_valid_json(): void
    {
        $fixturesPath = realpath(__DIR__.'/../Unit/Scanner/Fixtures');
        $this->app->singleton(
            AttributeScanner::class,
            fn () => new AttributeScanner([$fixturesPath]),
        );

        $this->artisan('query:scan', ['--list' => true, '--json' => true])
            ->assertSuccessful();
    }

    public function test_filter_option_narrows_results(): void
    {
        $fixturesPath = realpath(__DIR__.'/../Unit/Scanner/Fixtures');
        $this->app->singleton(
            AttributeScanner::class,
            fn () => new AttributeScanner([$fixturesPath]),
        );

        $this->artisan('query:scan', ['--list' => true, '--filter' => 'activeUsers'])
            ->expectsOutputToContain('1 diagnosable method')
            ->assertSuccessful();
    }

    public function test_filter_with_no_match_shows_warning(): void
    {
        $fixturesPath = realpath(__DIR__.'/../Unit/Scanner/Fixtures');
        $this->app->singleton(
            AttributeScanner::class,
            fn () => new AttributeScanner([$fixturesPath]),
        );

        $this->artisan('query:scan', ['--list' => true, '--filter' => 'nonExistentMethod'])
            ->expectsOutputToContain('No methods match filter')
            ->assertSuccessful();
    }

    public function test_scan_config_has_default_paths(): void
    {
        $paths = config('query-diagnostics.scan.paths');

        $this->assertIsArray($paths);
        $this->assertContains('app', $paths);
        $this->assertContains('Modules', $paths);
    }
}
