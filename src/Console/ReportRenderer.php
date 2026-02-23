<?php

declare(strict_types=1);

namespace QuerySentinel\Console;

use Illuminate\Console\Command;
use QuerySentinel\Support\DiagnosticReport;
use QuerySentinel\Support\EnvironmentContext;
use QuerySentinel\Support\ExecutionProfile;
use QuerySentinel\Support\Finding;
use QuerySentinel\Support\Report;

/**
 * Enhanced console formatter with traffic-light coloring.
 *
 * Renders full diagnostic reports with 11 sections (executive summary,
 * raw SQL, metrics, plan checklist, score breakdown, environment context,
 * execution profile, category-grouped findings, scalability, recommendations,
 * and full EXPLAIN ANALYZE plan).
 */
final class ReportRenderer
{
    /**
     * Render a full diagnostic report to the console.
     */
    public function render(Command $command, DiagnosticReport $diagnostic): void
    {
        $report = $diagnostic->report;
        $result = $report->result;
        $metrics = $result->metrics;

        $command->newLine();

        // 1. Executive Summary
        $this->renderExecutiveSummary($command, $diagnostic);

        // 2. Raw SQL
        $this->renderSql($command, $result->sql);

        // 3. EXPLAIN ANALYZE Summary
        $this->renderExplainSummary($command, $metrics, $result->executionTimeMs);

        // 4. Execution Plan Analysis (YES/NO checklist)
        $this->renderPlanChecklist($command, $metrics);

        // 5. Performance Score Breakdown
        $this->renderScoreBreakdown($command, $result->scores, $report->compositeScore, $report->grade);

        // 6. Environment Context
        if ($diagnostic->environment !== null) {
            $this->renderEnvironment($command, $diagnostic->environment);
        }

        // 7. Execution Profile
        if ($diagnostic->executionProfile !== null) {
            $this->renderExecutionProfile($command, $diagnostic->executionProfile);
        }

        // 8. Category-grouped findings
        $categories = [
            'rule' => 'Rule-Based Findings',
            'index_cardinality' => 'Index & Cardinality Analysis',
            'join_analysis' => 'Join Analysis',
            'plan_stability' => 'Plan Stability & Risk',
            'regression_safety' => 'Regression & Safety',
            'execution_metrics' => 'Execution Metrics',
            'environment' => 'Environment',
            'complexity' => 'Complexity Classification',
            'explain_why' => 'Explain Why',
        ];

        foreach ($categories as $cat => $title) {
            $catFindings = $diagnostic->findingsByCategory($cat);
            if (! empty($catFindings)) {
                $this->renderFindingsSection($command, $title, $catFindings);
            }
        }

        // 9. Scalability
        if (! empty($report->scalability)) {
            $this->renderScalability($command, $report->scalability);
        }

        // 10. Actionable Recommendations
        $actionable = array_filter(
            $diagnostic->findings,
            fn (Finding $f) => $f->recommendation !== null
        );
        if (! empty($actionable)) {
            $this->renderRecommendations($command, array_values($actionable));
        }

        // 11. Full EXPLAIN ANALYZE Plan
        $this->renderPlan($command, $result->plan);
    }

    /**
     * Render a shallow (basic) report to the console.
     */
    public function renderShallow(Command $command, Report $report): void
    {
        $counts = $report->findingCounts();

        $command->newLine();
        $command->line('=========================================================');
        $command->line('  QUERY SENTINEL - Performance Diagnostic Report');
        $command->line('=========================================================');
        $command->newLine();

        $statusLabel = $report->passed ? 'PASS' : 'FAIL';
        $statusDetail = ! $report->passed
            ? 'Critical issues detected'
            : ($counts['warning'] > 0 ? 'Warnings present' : 'No issues');
        $command->line(sprintf('  Status:     %s — %s', $statusLabel, $statusDetail));
        $command->line(sprintf('  Grade:      %s (%.1f / 100)', $report->grade, $report->compositeScore));
        $command->line(sprintf('  Time:       %.2fms', $report->result->executionTimeMs));
        $command->line(sprintf(
            '  Findings:   %d critical  %d warnings  %d optimizations  %d info',
            $counts['critical'],
            $counts['warning'],
            $counts['optimization'],
            $counts['info'],
        ));
        $command->line(sprintf('  Driver:     %s', $report->result->driver));
        $command->newLine();

        // Metrics
        $this->renderExplainSummary($command, $report->result->metrics, $report->result->executionTimeMs);
        $this->renderPlanChecklist($command, $report->result->metrics);
        $this->renderScoreBreakdown($command, $report->result->scores, $report->compositeScore, $report->grade);

        // Findings
        $findings = $report->result->findings;
        if (! empty($findings)) {
            $command->line('<fg=yellow>  Findings:</>');
            $command->line('  '.str_repeat('-', 70));
            foreach ($findings as $finding) {
                $severity = strtoupper($finding['severity'] ?? 'INFO');
                $title = $finding['title'] ?? 'Unknown';
                $command->line(sprintf('  [%s] %s', $severity, $title));
                if (! empty($finding['description'])) {
                    $command->line('    '.$finding['description']);
                }
                if (! empty($finding['recommendation'])) {
                    $command->line('    -> '.$finding['recommendation']);
                }
                $command->newLine();
            }
            $command->line('  '.str_repeat('-', 70));
            $command->newLine();
        }

        // Scalability
        if (! empty($report->scalability)) {
            $this->renderScalability($command, $report->scalability);
        }

        // Recommendations
        if (! empty($report->recommendations)) {
            $command->line('<fg=cyan;options=bold>  Actionable Recommendations:</>');
            $command->line('  '.str_repeat('-', 70));
            foreach ($report->recommendations as $i => $rec) {
                $command->line(sprintf('  %d. %s', $i + 1, $rec));
            }
            $command->line('  '.str_repeat('-', 70));
            $command->newLine();
        }

        // SQL
        $this->renderSql($command, $report->result->sql);

        // Plan
        $this->renderPlan($command, $report->result->plan);
    }

    private function renderExecutiveSummary(Command $command, DiagnosticReport $diagnostic): void
    {
        $worst = $diagnostic->worstSeverity();
        $color = $worst->consoleColor();
        $counts = $diagnostic->findingCounts();
        $report = $diagnostic->report;

        $statusLabel = match ($worst->value) {
            'critical' => 'FAIL — Critical issues detected',
            'warning' => 'WARN — Warnings present',
            'optimization' => 'OK — Optimizations available',
            default => 'PASS — No issues detected',
        };

        $command->line(sprintf('<fg=%s>=========================================================</>', $color));
        $command->line(sprintf('<fg=%s;options=bold>  PERFORMANCE ADVISORY REPORT</>', $color));
        $command->line(sprintf('<fg=%s>=========================================================</>', $color));
        $command->newLine();
        $command->line(sprintf('  Status:     <fg=%s;options=bold>%s</>', $color, $statusLabel));
        $command->line(sprintf('  Grade:      <fg=%s;options=bold>%s</> (%.1f / 100)', $color, $report->grade, $report->compositeScore));
        $command->line(sprintf('  Time:       <fg=white;options=bold>%.2fms</>', $report->result->executionTimeMs));
        $command->line(sprintf(
            '  Findings:   <fg=red>%d critical</>  <fg=yellow>%d warnings</>  <fg=green>%d optimizations</>  <fg=gray>%d info</>',
            $counts['critical'],
            $counts['warning'],
            $counts['optimization'],
            $counts['info'],
        ));
        $command->line(sprintf('  Driver:     %s', $report->result->driver));
        $command->newLine();
    }

    private function renderSql(Command $command, string $sql): void
    {
        $command->line('<fg=yellow>  Raw SQL:</>');
        $command->line('  '.str_repeat('-', 70));
        foreach (explode("\n", $this->formatSql($sql)) as $line) {
            $command->line('  '.$line);
        }
        $command->line('  '.str_repeat('-', 70));
        $command->newLine();
    }

    private function renderExplainSummary(Command $command, array $metrics, float $executionTimeMs): void
    {
        $command->line('<fg=yellow>  EXPLAIN ANALYZE Summary:</>');
        $command->line('  '.str_repeat('-', 70));
        $command->line(sprintf('  Total Execution Time:  <fg=white;options=bold>%.2fms</>', $executionTimeMs));
        $command->line(sprintf('  Rows Returned:         <fg=white;options=bold>%s</>', number_format($metrics['rows_returned'] ?? 0)));
        $command->line(sprintf('  Rows Examined:         <fg=white;options=bold>%s</>', number_format($metrics['rows_examined'] ?? 0)));
        $command->line(sprintf('  Max Loops:             <fg=white;options=bold>%s</>', number_format($metrics['max_loops'] ?? 0)));
        $command->line(sprintf('  Max Cost:              <fg=white;options=bold>%s</>', number_format($metrics['max_cost'] ?? 0, 2)));
        $command->line(sprintf('  Selectivity:           <fg=white;options=bold>%.1fx</>', $metrics['selectivity_ratio'] ?? 0));
        $command->line(sprintf('  Complexity:            <fg=white;options=bold>%s</>', $metrics['complexity_label'] ?? 'N/A'));
        $command->line('  '.str_repeat('-', 70));
        $command->newLine();
    }

    private function renderPlanChecklist(Command $command, array $metrics): void
    {
        $command->line('<fg=yellow>  Execution Plan Analysis:</>');
        $command->line('  '.str_repeat('-', 70));
        $this->renderCheck($command, 'Index Used', $metrics['is_index_backed'] ?? false);
        $this->renderCheck($command, 'Covering Index', $metrics['has_covering_index'] ?? false);
        $this->renderAntiCheck($command, 'Weedout', $metrics['has_weedout'] ?? false);
        $this->renderAntiCheck($command, 'Temporary Table', $metrics['has_temp_table'] ?? false);
        $this->renderAntiCheck($command, 'Filesort', $metrics['has_filesort'] ?? false);
        $this->renderAntiCheck($command, 'Table Scan', $metrics['has_table_scan'] ?? false);
        $this->renderAntiCheck($command, 'Index Merge', $metrics['has_index_merge'] ?? false);
        $this->renderAntiCheck($command, 'Disk Temp', $metrics['has_disk_temp'] ?? false);
        $this->renderCheck($command, 'Early Termination', $metrics['has_early_termination'] ?? false);

        $indexes = $metrics['indexes_used'] ?? [];
        if (! empty($indexes)) {
            $command->line(sprintf('  Indexes:  %s', implode(', ', $indexes)));
        }
        $command->line('  '.str_repeat('-', 70));
        $command->newLine();
    }

    private function renderScoreBreakdown(Command $command, array $scores, float $compositeScore, string $grade): void
    {
        $breakdown = $scores['breakdown'] ?? [];
        if (empty($breakdown)) {
            return;
        }

        $command->line('<fg=yellow>  Weighted Performance Score:</>');
        $command->line('  '.str_repeat('-', 70));
        $command->line(sprintf('  Composite Score:       <fg=white;options=bold>%.1f / 100</>', $compositeScore));
        $command->line(sprintf('  Grade:                 <fg=white;options=bold>%s</>', $grade));

        if ($scores['context_override'] ?? false) {
            $command->line('  Context Override:      <fg=green>Applied (LIMIT+covering+no filesort+fast)</>');
        }

        $command->line('  Component Breakdown:');
        foreach ($breakdown as $component => $data) {
            $score = $data['score'] ?? 0;
            $weight = ($data['weight'] ?? 0) * 100;
            $barLength = (int) round($score / 5);
            $bar = str_repeat('|', $barLength).str_repeat('.', 20 - $barLength);

            $command->line(sprintf(
                '    %-18s %3d/100  [%s]  (%.0f%% weight)',
                str_replace('_', ' ', $component),
                $score,
                $bar,
                $weight,
            ));
        }

        $command->line('  '.str_repeat('-', 70));
        $command->newLine();
    }

    private function renderEnvironment(Command $command, EnvironmentContext $env): void
    {
        $command->line('<fg=yellow>  Environment Context:</>');
        $command->line('  '.str_repeat('-', 70));
        $command->line(sprintf('  MySQL Version:         %s', $env->mysqlVersion));
        $command->line(sprintf(
            '  Buffer Pool:           %s MB (%.0f%% utilized)',
            round($env->bufferPoolSizeBytes / (1024 * 1024), 1),
            $env->bufferPoolUtilization * 100
        ));
        $command->line(sprintf('  InnoDB I/O Capacity:   %s', number_format($env->innodbIoCapacity)));
        $command->line(sprintf('  Page Size:             %s bytes', number_format($env->innodbPageSize)));
        $command->line(sprintf('  Tmp Table Size:        %s', number_format($env->tmpTableSize)));
        $command->line(sprintf(
            '  Cache Status:          %s',
            $env->isColdCache ? '<fg=yellow>COLD</>' : '<fg=green>WARM</>'
        ));
        $command->line('  '.str_repeat('-', 70));
        $command->newLine();
    }

    private function renderExecutionProfile(Command $command, ExecutionProfile $profile): void
    {
        $command->line('<fg=yellow>  Execution Profile:</>');
        $command->line('  '.str_repeat('-', 70));
        $command->line(sprintf(
            '  Scan Complexity:       %s (%s)',
            $profile->scanComplexity->value,
            $profile->scanComplexity->label()
        ));
        $command->line(sprintf(
            '  Sort Complexity:       %s (%s)',
            $profile->sortComplexity->value,
            $profile->sortComplexity->label()
        ));
        $command->line(sprintf('  Nested Loop Depth:     %d', $profile->nestedLoopDepth));
        $command->line(sprintf('  Logical Reads:         %s', number_format($profile->logicalReads)));
        $command->line(sprintf('  Physical Reads:        %s', number_format($profile->physicalReads)));

        if (! empty($profile->joinFanouts)) {
            $command->line('  Join Fanouts:');
            foreach ($profile->joinFanouts as $table => $fanout) {
                $command->line(sprintf('    %-20s %s rows', $table, number_format((int) $fanout)));
            }
        }

        if (! empty($profile->btreeDepths)) {
            $command->line('  B-Tree Depths:');
            foreach ($profile->btreeDepths as $index => $depth) {
                $command->line(sprintf('    %-20s depth %d', $index, $depth));
            }
        }

        $command->line('  '.str_repeat('-', 70));
        $command->newLine();
    }

    /**
     * @param  Finding[]  $findings
     */
    private function renderFindingsSection(Command $command, string $title, array $findings): void
    {
        $command->line(sprintf('<fg=yellow>  %s:</>', $title));
        $command->line('  '.str_repeat('-', 70));

        foreach ($findings as $finding) {
            $color = $finding->severity->consoleColor();
            $icon = $finding->severity->icon();
            $command->line(sprintf('  <fg=%s>[%s]</> %s', $color, $icon, $finding->title));
            $command->line(sprintf('  <fg=gray>  %s</>', $finding->description));

            if ($finding->recommendation !== null) {
                $command->line(sprintf('  <fg=white>  -> %s</>', $finding->recommendation));
            }
        }

        $command->line('  '.str_repeat('-', 70));
        $command->newLine();
    }

    private function renderScalability(Command $command, array $scalability): void
    {
        $risk = $scalability['risk'] ?? 'N/A';
        $riskColor = match ($risk) {
            'LOW' => 'green',
            'MEDIUM' => 'yellow',
            'HIGH' => 'red',
            default => 'white',
        };

        $command->line('<fg=yellow>  Scalability Estimation:</>');
        $command->line('  '.str_repeat('-', 70));
        $command->line(sprintf('  Current Rows:          %s', number_format($scalability['current_rows'] ?? 0)));
        $command->line(sprintf('  Risk:                  <fg=%s;options=bold>%s</>', $riskColor, $risk));

        $projections = $scalability['projections'] ?? [];
        foreach ($projections as $p) {
            $command->line(sprintf(
                '    at %sM:  %s  (projected %.1fms)',
                number_format(($p['target_rows'] ?? 0) / 1_000_000),
                $p['label'] ?? '',
                $p['projected_time_ms'] ?? 0,
            ));
        }

        $limitSensitivity = $scalability['limit_sensitivity'] ?? [];
        if (! empty($limitSensitivity)) {
            $command->line('  LIMIT Sensitivity:');
            foreach ($limitSensitivity as $limit => $data) {
                $command->line(sprintf(
                    '    LIMIT %-6d %.2fms  (%s)',
                    $limit,
                    $data['projected_time_ms'] ?? 0,
                    $data['note'] ?? '',
                ));
            }
        }

        $command->line('  '.str_repeat('-', 70));
        $command->newLine();
    }

    /**
     * @param  Finding[]  $findings
     */
    private function renderRecommendations(Command $command, array $findings): void
    {
        $command->line('<fg=cyan;options=bold>  Actionable Recommendations:</>');
        $command->line('  '.str_repeat('-', 70));

        foreach ($findings as $finding) {
            $color = $finding->severity->consoleColor();
            $icon = $finding->severity->icon();
            $command->line(sprintf('  <fg=%s>[%s]</> %s', $color, $icon, $finding->title));
            $command->line(sprintf('  <fg=white>  -> %s</>', $finding->recommendation));
        }

        $command->line('  '.str_repeat('-', 70));
        $command->newLine();
    }

    private function renderPlan(Command $command, string $plan): void
    {
        $command->line('<fg=yellow>  Full EXPLAIN ANALYZE Plan:</>');
        $command->line('  '.str_repeat('-', 70));
        foreach (explode("\n", $plan) as $line) {
            $command->line('  '.$line);
        }
        $command->line('  '.str_repeat('-', 70));
        $command->newLine();
    }

    private function renderCheck(Command $command, string $label, bool $value): void
    {
        $icon = $value ? '<fg=green>YES</>' : '<fg=red>NO</>';
        $command->line(sprintf('  %-22s %s', $label.':', $icon));
    }

    private function renderAntiCheck(Command $command, string $label, bool $value): void
    {
        $icon = $value ? '<fg=red>YES (bad)</>' : '<fg=green>NO (good)</>';
        $command->line(sprintf('  %-22s %s', $label.':', $icon));
    }

    private function formatSql(string $sql): string
    {
        $keywords = ['SELECT', 'FROM', 'WHERE', 'AND', 'OR', 'ORDER BY', 'LIMIT', 'EXISTS', 'INNER JOIN', 'LEFT JOIN', 'GROUP BY', 'HAVING'];
        foreach ($keywords as $keyword) {
            $sql = preg_replace('/\b'.$keyword.'\b/i', "\n  ".$keyword, $sql);
        }

        return trim($sql);
    }
}
