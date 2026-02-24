<?php

declare(strict_types=1);

namespace QuerySentinel\Validation;

use QuerySentinel\Exceptions\EngineAbortException;
use QuerySentinel\Support\SqlParser;
use QuerySentinel\Support\ValidationFailureReport;

/**
 * Validates JOIN conditions: aliases exist, ON columns reference valid tables.
 * Assumes SchemaValidator has already run (tables and columns exist).
 */
final class JoinValidator
{
    /**
     * Validate join structure. Throws on invalid join.
     *
     * @param  array<string, string>  $aliasToTable
     * @throws EngineAbortException
     */
    public function validate(string $sql, array $aliasToTable): void
    {
        $tables = SqlParser::extractTables($sql);
        $joinCols = SqlParser::extractJoinColumns($sql);

        foreach ($joinCols as $colRef) {
            $parts = explode('.', $colRef);
            if (count($parts) !== 2) {
                continue;
            }
            [$tableOrAlias, $column] = $parts;
            $resolvedTable = $aliasToTable[$tableOrAlias] ?? $tableOrAlias;

            if (! in_array($resolvedTable, $tables, true) && ! in_array($tableOrAlias, $tables, true)) {
                throw new EngineAbortException(
                    'Invalid join condition',
                    new ValidationFailureReport(
                        status: 'ERROR — Invalid Join Condition',
                        failureStage: 'Join Validation',
                        detailedError: "Column {$colRef} references unknown table or alias",
                        recommendations: [
                            'Ensure table aliases in JOIN ON match defined aliases',
                            'Verify columns in ON exist on the joined tables',
                        ],
                        missingColumn: $column,
                        missingTable: $tableOrAlias,
                    )
                );
            }
        }
    }
}
