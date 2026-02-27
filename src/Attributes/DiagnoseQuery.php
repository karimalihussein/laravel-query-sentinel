<?php

declare(strict_types=1);

namespace QuerySentinel\Attributes;

use Attribute;

/**
 * Mark a method that returns a Query Builder for interactive diagnosis.
 *
 * When placed on a method that returns an Eloquent\Builder or Query\Builder,
 * the `query:scan` command will discover it, allow interactive selection,
 * extract the SQL, and run full EXPLAIN ANALYZE diagnostics.
 *
 * The method must have no required parameters (all optional or zero params).
 * The method must return a Builder instance — it should NOT execute the query.
 *
 * Usage:
 *
 *   #[DiagnoseQuery]
 *   public function activeUsersQuery(): Builder { ... }
 *
 *   #[DiagnoseQuery(label: 'Slow lead search', description: 'Lead search with all filters')]
 *   public function leadSearchQuery(): Builder { ... }
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class DiagnoseQuery
{
    /**
     * @param  string  $label  Display label in the interactive list (empty = ClassName::method)
     * @param  string  $description  Optional description shown alongside the label
     */
    public function __construct(
        public readonly string $label = '',
        public readonly string $description = '',
    ) {}
}
