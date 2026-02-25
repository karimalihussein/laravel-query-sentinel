<?php

declare(strict_types=1);

namespace QuerySentinel\Validation;

use Illuminate\Support\Facades\DB;
use QuerySentinel\Contracts\DriverInterface;
use QuerySentinel\Support\TypoIntelligence;
use QuerySentinel\Support\ValidationFailureReport;
use QuerySentinel\Support\ValidationResult;

/**
 * Validates SQL syntax via EXPLAIN (without ANALYZE).
 * Returns ValidationResult; no exceptions.
 */
final class SyntaxValidator
{
    public function __construct(
        private readonly ?string $connection = null,
        private readonly ?DriverInterface $driver = null,
    ) {}

    /**
     * Validate syntax. Returns ValidationResult (invalid on EXPLAIN failure).
     */
    public function validate(string $sql): ValidationResult
    {
        $conn = $this->connection ?? config('query-diagnostics.connection');

        try {
            if ($this->driver !== null) {
                $this->driver->runExplain($sql);
            } else {
                DB::connection($conn)->select('EXPLAIN '.$sql);
            }
        } catch (\Throwable $e) {
            return ValidationResult::invalid([
                $this->buildFailureReport($e, $sql),
            ]);
        }

        return ValidationResult::valid();
    }

    private function buildFailureReport(\Throwable $e, string $sql): ValidationFailureReport
    {
        $message = $e->getMessage();
        $sqlstate = null;
        if (preg_match('/SQLSTATE\[(\w+)\]/', $message, $m)) {
            $sqlstate = $m[1];
        }
        $lineNum = null;
        if (preg_match('/at line (\d+)/i', $message, $m)) {
            $lineNum = (int) $m[1];
        }

        $recommendations = [
            'Fix missing keywords',
            'Fix unmatched parentheses',
            'Fix malformed expressions',
        ];

        $suggestion = null;
        foreach (['SELEC', 'FORM', 'WERE', 'ORDE', 'GROP', 'LIMT'] as $typo) {
            if (stripos($sql, $typo) !== false) {
                $suggestion = TypoIntelligence::suggestKeyword($typo);
                if ($suggestion !== null) {
                    $recommendations[] = "Possible typo: Did you mean {$suggestion}?";
                    break;
                }
            }
        }

        return new ValidationFailureReport(
            status: 'ERROR — Invalid SQL Syntax',
            failureStage: 'Syntax',
            detailedError: $message,
            sqlstateCode: $sqlstate,
            lineNumber: $lineNum,
            recommendations: $recommendations,
            suggestion: $suggestion,
        );
    }
}
