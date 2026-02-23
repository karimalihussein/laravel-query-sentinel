<?php

declare(strict_types=1);

namespace QuerySentinel\Adapters;

use QuerySentinel\Contracts\AdapterInterface;
use QuerySentinel\Contracts\AnalyzerInterface;
use QuerySentinel\Support\ExecutionGuard;
use QuerySentinel\Support\Report;
use QuerySentinel\Support\SqlSanitizer;

/**
 * Raw SQL adapter.
 *
 * Validates and sanitizes a raw SQL string before delegating
 * to the core analyzer. This adapter is framework-agnostic.
 */
final class SqlAdapter implements AdapterInterface
{
    public function __construct(
        private readonly AnalyzerInterface $analyzer,
        private readonly ExecutionGuard $guard,
        private readonly SqlSanitizer $sanitizer,
    ) {}

    /**
     * Analyze a raw SQL string.
     *
     * @param  string  $input  Raw SQL query
     */
    public function analyze(mixed $input): Report
    {
        if (! is_string($input)) {
            throw new \InvalidArgumentException('SqlAdapter expects a string SQL query.');
        }

        $sql = $this->sanitizer->sanitize($input);
        $this->guard->validate($sql);

        return $this->analyzer->analyze($sql, 'sql');
    }
}
