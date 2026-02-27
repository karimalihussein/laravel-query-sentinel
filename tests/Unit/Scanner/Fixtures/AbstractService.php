<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Unit\Scanner\Fixtures;

use QuerySentinel\Attributes\DiagnoseQuery;

/**
 * @internal Abstract classes should be skipped by the scanner.
 */
abstract class AbstractService
{
    #[DiagnoseQuery(label: 'Should be skipped')]
    public function abstractQuery(): void {}
}
