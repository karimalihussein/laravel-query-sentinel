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
}
