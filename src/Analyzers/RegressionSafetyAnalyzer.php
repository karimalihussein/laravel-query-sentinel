<?php

declare(strict_types=1);

namespace QuerySentinel\Analyzers;

use QuerySentinel\Enums\Severity;
use QuerySentinel\Support\Finding;

/**
 * Section 8: Regression & Safety Check.
 *
 * Detects implicit type conversions, collation mismatches, and charset
 * conversion warnings that can silently prevent index usage.
 */
final class RegressionSafetyAnalyzer
{
    /**
     * @param  string  $rawSql  The original SQL
     * @param  string  $plan  EXPLAIN ANALYZE output
     * @param  array<string, mixed>  $metrics  From MetricsExtractor
     * @param  array<int, array<string, mixed>>  $explainRows  From EXPLAIN tabular output
     * @return array{safety: array<string, mixed>, findings: Finding[]}
     */
    public function analyze(string $rawSql, string $plan, array $metrics, array $explainRows): array
    {
        $findings = [];
        $safety = [];

        $typeConversions = $this->detectImplicitTypeConversions($plan, $rawSql);
        $safety['type_conversions'] = $typeConversions;

        foreach ($typeConversions as $conv) {
            $findings[] = new Finding(
                severity: Severity::Warning,
                category: 'regression_safety',
                title: sprintf('Implicit type conversion: %s', $conv['description']),
                description: 'Implicit type conversion forces MySQL to apply a function on the indexed column, preventing index usage.',
                recommendation: 'Ensure compared values match the column type. Cast the value, not the column.',
                metadata: $conv,
            );
        }

        $collationIssues = $this->detectCollationMismatches($plan);
        $safety['collation_mismatches'] = $collationIssues;

        foreach ($collationIssues as $issue) {
            $findings[] = new Finding(
                severity: Severity::Warning,
                category: 'regression_safety',
                title: sprintf('Collation issue: %s', $issue['description']),
                description: 'Different collations on join columns force MySQL to convert character sets at runtime, potentially preventing index usage.',
                recommendation: 'ALTER the columns to use the same collation.',
                metadata: $issue,
            );
        }

        if (preg_match('/convert.*charset|charset.*convert/i', $plan)) {
            $safety['has_charset_conversion'] = true;
            $findings[] = new Finding(
                severity: Severity::Warning,
                category: 'regression_safety',
                title: 'Character set conversion detected in execution plan',
                description: 'The execution plan contains character set conversion operations, which add CPU overhead per row.',
                recommendation: 'Ensure all joined tables and columns use the same character set (preferably utf8mb4).',
            );
        } else {
            $safety['has_charset_conversion'] = false;
        }

        return ['safety' => $safety, 'findings' => $findings];
    }

    /**
     * Detect implicit type conversions from EXPLAIN ANALYZE plan and SQL analysis.
     *
     * @return array<int, array{description: string, column: string}>
     */
    private function detectImplicitTypeConversions(string $plan, string $rawSql): array
    {
        $conversions = [];

        // Pattern: cast(column as type) in EXPLAIN ANALYZE
        if (preg_match_all('/cast\((\w+(?:\.\w+)?)\s+as\s+(\w+)\)/i', $plan, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $conversions[] = [
                    'description' => "CAST({$m[1]} AS {$m[2]})",
                    'column' => $m[1],
                ];
            }
        }

        // Pattern: convert(expression using charset) in plan
        if (preg_match_all('/convert\((\w+(?:\.\w+)?)\s+using\s+(\w+)\)/i', $plan, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $conversions[] = [
                    'description' => "CONVERT({$m[1]} USING {$m[2]})",
                    'column' => $m[1],
                ];
            }
        }

        return $conversions;
    }

    /**
     * Detect collation mismatches from EXPLAIN ANALYZE plan text.
     *
     * @return array<int, array{description: string}>
     */
    private function detectCollationMismatches(string $plan): array
    {
        $issues = [];

        if (preg_match_all('/collation.*?mismatch|convert.*?collation/i', $plan, $matches)) {
            foreach ($matches[0] as $match) {
                $issues[] = ['description' => trim($match)];
            }
        }

        return $issues;
    }
}
