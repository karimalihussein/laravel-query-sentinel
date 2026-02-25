<?php

declare(strict_types=1);

namespace QuerySentinel\Validation;

use QuerySentinel\Support\SqlParser;
use QuerySentinel\Support\ValidationFailureReport;
use QuerySentinel\Support\ValidationResult;

/**
 * Validates JOIN conditions: aliases exist, ON columns reference valid tables.
 * Assumes SchemaValidator has already run (tables and columns exist).
 * Returns ValidationResult; no exceptions.
 */
final class JoinValidator
{
    /**
     * Validate join structure. Returns ValidationResult.
     *
     * @param  array<string, string>  $aliasToTable
     */
    public function validate(string $sql, array $aliasToTable): ValidationResult
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
                return ValidationResult::invalid([
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
                    ),
                ]);
            }
        }

        return ValidationResult::valid();
    }
}
