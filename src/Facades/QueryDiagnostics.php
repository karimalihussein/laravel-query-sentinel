<?php

declare(strict_types=1);

namespace QuerySentinel\Facades;

use Illuminate\Support\Facades\Facade;
use QuerySentinel\Core\ProfileReport;
use QuerySentinel\Support\Report;

/**
 * @method static Report analyze(string $sql)
 * @method static Report analyzeSql(string $sql)
 * @method static Report analyzeBuilder(object $builder)
 * @method static ProfileReport profile(\Closure $callback)
 * @method static ProfileReport profileClass(string $class, string $method, array $arguments = [])
 *
 * @see \QuerySentinel\Core\Engine
 */
final class QueryDiagnostics extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \QuerySentinel\Core\Engine::class;
    }
}
