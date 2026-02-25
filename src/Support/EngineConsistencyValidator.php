<?php

declare(strict_types=1);

namespace QuerySentinel\Support;

use QuerySentinel\Exceptions\EngineAbortException;

/**
 * Engine consistency enforcement.
 *
 * - validateBeforeReportResult(): returns result (no throw); use for result-based flow.
 * - validateBeforeReport(): throws on invalid state (legacy).
 * - validate(): post-analysis contradiction detector (log-only, graceful).
 */
final class EngineConsistencyValidator
{
    /**
     * Validate that metrics/plan are valid before report generation.
     * Returns result; does not throw.
     *
     * @param  array<string, mixed>  $metrics
     * @return array{valid: bool, failure: ?ValidationFailureReport}
     */
    public function validateBeforeReportResult(
        string $plan,
        array $metrics,
        bool $syntaxValid = true,
        bool $schemaValid = true,
    ): array {
        $accessType = $metrics['primary_access_type'] ?? $metrics['access_type'] ?? null;
        $isUnknown = $accessType === 'unknown' || $accessType === 'UNKNOWN' || $accessType === null;
        $planEmpty = trim($plan) === '' || str_starts_with(trim($plan), '-- EXPLAIN failed:');

        if ($isUnknown || $planEmpty || ! $syntaxValid || ! $schemaValid) {
            return [
                'valid' => false,
                'failure' => new ValidationFailureReport(
                    status: 'ERROR — Analysis Aborted',
                    failureStage: 'Engine Consistency',
                    detailedError: 'Invalid engine state: access_type='.($accessType ?? 'null')
                        .', plan_empty='.($planEmpty ? 'yes' : 'no')
                        .', syntax_valid='.($syntaxValid ? 'yes' : 'no')
                        .', schema_valid='.($schemaValid ? 'yes' : 'no'),
                    recommendations: [
                        'Do not generate performance report without valid explain plan.',
                        'Ensure validation pipeline passed before analysis.',
                    ],
                ),
            ];
        }

        return ['valid' => true, 'failure' => null];
    }

    /**
     * Validate that metrics/plan are valid before report generation.
     * Throws EngineAbortException if state is invalid (legacy).
     *
     * @param  array<string, mixed>  $metrics
     *
     * @throws EngineAbortException
     */
    public function validateBeforeReport(
        string $plan,
        array $metrics,
        bool $syntaxValid = true,
        bool $schemaValid = true,
    ): void {
        $result = $this->validateBeforeReportResult($plan, $metrics, $syntaxValid, $schemaValid);

        if (! $result['valid'] && $result['failure'] !== null) {
            throw new EngineAbortException('Engine state invalid — cannot produce performance report', $result['failure']);
        }
    }

    /**
     * Post-analysis consistency validation.
     *
     * Detects contradictions between metrics and findings that indicate
     * engine logic bugs. Violations are logged but never thrown (graceful degradation).
     *
     * @param  array<string, mixed>  $metrics
     * @param  Finding[]  $findings
     * @param  array<string, mixed>|null  $concurrency
     * @return array{valid: bool, violations: string[]}
     */
    public function validate(array $metrics, array $findings, ?array $concurrency = null, ?string $sql = null): array
    {
        $violations = [];

        $accessType = $metrics['primary_access_type'] ?? null;
        $isIndexBacked = $metrics['is_index_backed'] ?? null;
        $hasTableScan = $metrics['has_table_scan'] ?? false;
        $complexityRisk = $metrics['complexity_risk'] ?? null;

        // Rule 1: Non-table-scan access with is_index_backed=false is contradictory
        if ($accessType !== null && $accessType !== 'table_scan' && $isIndexBacked === false) {
            $violations[] = sprintf(
                'Contradiction: primary_access_type=%s but is_index_backed=false',
                $accessType
            );
        }

        // Rule 2: has_table_scan=true with non-table_scan access type
        if ($hasTableScan && $accessType !== null && $accessType !== 'table_scan') {
            $violations[] = sprintf(
                'Contradiction: has_table_scan=true but primary_access_type=%s',
                $accessType
            );
        }

        // Rule 3: Scalability risk LOW with has_table_scan=true on large tables
        // Exception: intentional scans (no WHERE/JOIN/etc.) are legitimately LOW risk
        if ($complexityRisk === 'LOW' && $hasTableScan) {
            $isIntentional = $metrics['is_intentional_scan'] ?? false;
            $currentRows = $metrics['current_rows'] ?? $metrics['rows_examined'] ?? 0;
            if ($currentRows > 1_000 && ! $isIntentional) {
                $violations[] = 'Contradiction: complexity_risk=LOW but has_table_scan=true on table with >1000 rows';
            }
        }

        // Rule 4: Duplicate findings (identical category + title + recommendation)
        $seen = [];
        foreach ($findings as $finding) {
            $key = $finding->category.'|'.$finding->title.'|'.($finding->recommendation ?? '');
            if (isset($seen[$key])) {
                $violations[] = sprintf(
                    'Duplicate finding: category=%s, title=%s',
                    $finding->category,
                    $finding->title
                );
            }
            $seen[$key] = true;
        }

        // Rule 5: lock_scope != 'none' for plain SELECT (InnoDB MVCC violation)
        if ($concurrency !== null && $sql !== null) {
            $lockScope = $concurrency['lock_scope'] ?? null;
            $isSelect = (bool) preg_match('/^\s*SELECT\b/i', $sql);
            $isLockingRead = (bool) preg_match('/\b(FOR\s+UPDATE|FOR\s+SHARE|LOCK\s+IN\s+SHARE\s+MODE)\b/i', $sql);

            if ($lockScope !== null && $lockScope !== 'none' && $isSelect && ! $isLockingRead) {
                $violations[] = sprintf(
                    'Contradiction: lock_scope=%s for plain SELECT (should be none under MVCC)',
                    $lockScope
                );
            }
        }

        // Rule 6: primary_access_type=table_scan but has_table_scan=false
        if ($accessType === 'table_scan' && ! $hasTableScan) {
            $violations[] = 'Contradiction: primary_access_type=table_scan but has_table_scan=false';
        }

        // Rule 7: Intentional scan must not have critical/warning index-related findings
        if ($metrics['is_intentional_scan'] ?? false) {
            foreach ($findings as $finding) {
                if (in_array($finding->category, ['no_index', 'full_table_scan'], true)
                    && in_array($finding->severity, [\QuerySentinel\Enums\Severity::Critical, \QuerySentinel\Enums\Severity::Warning], true)) {
                    $violations[] = sprintf(
                        'Contradiction: intentional scan has %s-severity %s finding',
                        $finding->severity->value,
                        $finding->category
                    );
                }
            }
        }

        // Rule 8: Regression finding should not exist when baseline is below minimum measurable
        foreach ($findings as $finding) {
            if ($finding->category === 'regression'
                && isset($finding->metadata['metric'])
                && $finding->metadata['metric'] === 'execution_time_ms'
                && isset($finding->metadata['baseline_value'])
                && $finding->metadata['baseline_value'] < 5.0) {
                $violations[] = sprintf(
                    'Contradiction: regression finding for execution_time_ms with baseline %.2fms (below minimum measurable)',
                    $finding->metadata['baseline_value']
                );
            }
        }

        // Rule 9: parsing_valid=false but non-zero execution time is contradictory
        $parsingValid = $metrics['parsing_valid'] ?? true;
        $executionTimeMs = $metrics['execution_time_ms'] ?? 0.0;
        if (! $parsingValid && $executionTimeMs > 0.0) {
            $violations[] = sprintf(
                'Contradiction: parsing_valid=false but execution_time_ms=%.2f',
                $executionTimeMs
            );
        }

        return [
            'valid' => $violations === [],
            'violations' => $violations,
        ];
    }
}
