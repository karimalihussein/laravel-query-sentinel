<?php

declare(strict_types=1);

namespace QuerySentinel\Exceptions;

use QuerySentinel\Core\ProfileReport;

/**
 * Thrown when failOnCritical is enabled and a performance violation is detected.
 *
 * Conditions that trigger this exception:
 *   - Worst query grade is D or F
 *   - Any individual query execution exceeds 500ms
 *   - A full table scan is detected
 *   - N+1 query pattern detected
 */
final class PerformanceViolationException extends \RuntimeException
{
    public function __construct(
        public readonly ProfileReport $report,
        string $reason,
        public readonly string $class = '',
        public readonly string $method = '',
    ) {
        parent::__construct(sprintf(
            'QuerySentinel performance violation in %s::%s — %s',
            class_basename($class),
            $method,
            $reason,
        ));
    }

    public static function fromReport(
        ProfileReport $report,
        string $class,
        string $method,
    ): self {
        $reasons = [];

        $worstGrade = $report->worstGrade();
        if (in_array($worstGrade, ['D', 'F'], true)) {
            $reasons[] = sprintf('grade %s detected', $worstGrade);
        }

        if ($report->slowestQuery && $report->slowestQuery->result->executionTimeMs > 500) {
            $reasons[] = sprintf(
                'slow query %.0fms',
                $report->slowestQuery->result->executionTimeMs,
            );
        }

        if ($report->nPlusOneDetected) {
            $reasons[] = 'N+1 query pattern';
        }

        foreach ($report->individualReports as $r) {
            if ($r->result->metrics['has_table_scan'] ?? false) {
                $reasons[] = 'full table scan';

                break;
            }
        }

        $reason = empty($reasons) ? 'critical findings detected' : implode(', ', $reasons);

        return new self($report, $reason, $class, $method);
    }
}
