<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Unit\Scanner\Fixtures;

use QuerySentinel\Attributes\DiagnoseQuery;

/**
 * @internal Test fixture for AttributeScanner tests.
 */
final class SampleQueryService
{
    #[DiagnoseQuery(label: 'Active users query')]
    public function activeUsersQuery(): void {}

    #[DiagnoseQuery]
    public function recentOrdersQuery(): void {}

    #[DiagnoseQuery(label: 'Slow search', description: 'Full-text search across leads')]
    public function leadSearchQuery(): void {}

    public function nonAnnotatedMethod(): void {}

    public function anotherRegularMethod(string $param): string
    {
        return $param;
    }
}
