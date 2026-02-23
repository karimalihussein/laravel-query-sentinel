<?php

declare(strict_types=1);

namespace QuerySentinel\Facades;

use Illuminate\Support\Facades\Facade;
use QuerySentinel\Core\ProfileReport;
use QuerySentinel\Support\Report;

/**
 * Primary facade for the Query Sentinel package.
 *
 * Usage:
 *   QuerySentinel::analyzeSql('SELECT ...');
 *   QuerySentinel::analyzeBuilder(User::where(...));
 *   QuerySentinel::profile(fn() => $service->index());
 *   QuerySentinel::profileClass(Service::class, 'index', [$args]);
 *
 * @method static Report analyze(string $sql)
 * @method static Report analyzeSql(string $sql)
 * @method static Report analyzeBuilder(object $builder)
 * @method static ProfileReport profile(\Closure $callback)
 * @method static ProfileReport profileClass(string $class, string $method, array $arguments = [])
 *
 * @see \QuerySentinel\Core\Engine
 */
final class QuerySentinel extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \QuerySentinel\Core\Engine::class;
    }
}
