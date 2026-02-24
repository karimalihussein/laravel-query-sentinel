<?php

declare(strict_types=1);

namespace QuerySentinel\Console;

use Illuminate\Console\Command;
use QuerySentinel\Support\DiagnosticReport;
use QuerySentinel\Support\EnvironmentContext;
use QuerySentinel\Support\ExecutionProfile;
use QuerySentinel\Support\Finding;
use QuerySentinel\Support\Report;
use QuerySentinel\Support\ValidationFailureReport;

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

        // 8. Cardinality Drift (Phase 1)
        if ($diagnostic->cardinalityDrift !== null) {
            $this->renderCardinalityDrift($command, $diagnostic->cardinalityDrift);
        }

        // 9. Category-grouped findings
        $categories = [
            'rule' => 'Rule-Based Findings',
            'index_cardinality' => 'Index & Cardinality Analysis',
            'cardinality_drift' => 'Cardinality Drift',
            'join_analysis' => 'Join Analysis',
            'anti_pattern' => 'SQL Anti-Patterns',
            'index_synthesis' => 'Index Recommendations',
            'memory_pressure' => 'Memory Pressure',
            'concurrency' => 'Concurrency Risk',
            'plan_stability' => 'Plan Stability & Risk',
            'regression_safety' => 'Regression & Safety',
            // 'regression' omitted — rendered by dedicated renderRegression() section
            'hypothetical_index' => 'Hypothetical Index Results',
            'workload' => 'Workload Patterns',
            'confidence' => 'Confidence',
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

        // 10. Confidence Score (Phase 5)
        if ($diagnostic->confidence !== null) {
            $this->renderConfidence($command, $diagnostic->confidence);
        }

        // 12. Memory Pressure (Phase 7)
        if ($diagnostic->memoryPressure !== null) {
            $this->renderMemoryPressure($command, $diagnostic->memoryPressure);
        }

        // 13. Concurrency Risk (Phase 6)
        if ($diagnostic->concurrencyRisk !== null) {
            $this->renderConcurrencyRisk($command, $diagnostic->concurrencyRisk);
        }

        // 14. Regression Baselines (Phase 9)
        if ($diagnostic->regression !== null) {
            $this->renderRegression($command, $diagnostic->regression);
        }

        // 15. Hypothetical Index Results (Phase 10)
        if ($diagnostic->hypotheticalIndexes !== null) {
            $this->renderHypotheticalIndexes($command, $diagnostic->hypotheticalIndexes);
        }

        // 15b. Workload Patterns
        if ($diagnostic->workload !== null) {
            $this->renderWorkload($command, $diagnostic->workload);
        }

        // 16. Scalability
        if (! empty($report->scalability)) {
            $this->renderScalability($command, $report->scalability);
        }

        // 17. Actionable Recommendations (deduplicated by recommendation text)
        $seen = [];
        $actionable = [];
        foreach ($diagnostic->findings as $f) {
            if ($f->recommendation !== null && ! isset($seen[$f->recommendation])) {
                $seen[$f->recommendation] = true;
                $actionable[] = $f;
            }
        }
        if (! empty($actionable)) {
            $this->renderRecommendations($command, $actionable);
        }

        // 18. Full EXPLAIN ANALYZE Plan
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

    /**
     * Render validation failure report (no scoring, no performance section).
     */
    public function renderValidationFailure(Command $command, ValidationFailureReport $report, string $sql): void
    {
        $command->newLine();
        $command->line('<fg=red>=========================================================</>');
        $command->line('<fg=red;options=bold>  PERFORMANCE ADVISORY REPORT</></>');
        $command->line('<fg=red>=========================================================</>');
        $command->newLine();
        $command->line('  <fg=red;options=bold>Status: ERROR — Validation Failed</>');
        $command->line('  Grade: N/A');
        $command->line('  Analysis: Aborted');
        $command->newLine();
        $command->line(sprintf('  Failure Stage: %s', $report->failureStage));
        $command->newLine();
        $command->line('  Detailed Error:');
        $command->line('  ---------------------------------------------');
        $command->line('  ' . str_replace("\n", "\n  ", $report->detailedError));
        if ($report->sqlstateCode !== null) {
            $command->line(sprintf('  SQLSTATE: %s', $report->sqlstateCode));
        }
        if ($report->lineNumber !== null) {
            $command->line(sprintf('  Line: %d', $report->lineNumber));
        }
        $command->line('  ---------------------------------------------');
        $command->newLine();
        if (! empty($report->recommendations)) {
            $command->line('<fg=yellow>  Recommendation:</>');
            foreach ($report->recommendations as $rec) {
                $command->line(sprintf('  - %s', $rec));
            }
            $command->newLine();
        }
        if ($report->suggestion !== null) {
            $command->line(sprintf('  <fg=cyan>Did you mean: %s ?</>', $report->suggestion));
            $command->newLine();
        }
        if ($report->missingTable !== null) {
            $command->line(sprintf('  Missing Table: %s', $report->missingTable));
            if ($report->database !== null) {
                $command->line(sprintf('  Database: %s', $report->database));
            }
            $command->newLine();
        }
        if ($report->missingColumn !== null && $report->missingTable !== null) {
            $command->line(sprintf('  Missing Column: %s (Table: %s)', $report->missingColumn, $report->missingTable));
            $command->newLine();
        }
        $command->line('  Raw SQL:');
        $command->line('  ----------------------------------------------------------------------');
        $command->line('  ' . str_replace("\n", "\n  ", trim($sql)));
        $command->line('  ----------------------------------------------------------------------');
        $command->newLine();
    }

    private function renderExecutiveSummary(Command $command, DiagnosticReport $diagnostic): void
    {
        $worst = $diagnostic->worstSeverity();
        $counts = $diagnostic->findingCounts();
        $report = $diagnostic->report;

        // Use confidence-adjusted grade and score
        $effectiveGrade = $diagnostic->effectiveGrade();
        $effectiveScore = $diagnostic->effectiveCompositeScore();

        // When effective score is high and no critical findings, status reflects
        // the score (not worst severity) to avoid Score=100 + Status=WARN contradiction.
        if ($effectiveScore >= 90.0 && $counts['critical'] === 0) {
            if ($counts['warning'] > 0 || $counts['optimization'] > 0) {
                $statusLabel = 'OK — Minor observations present';
                $color = 'green';
            } else {
                $statusLabel = 'PASS — No issues detected';
                $color = 'green';
            }
        } else {
            $color = $worst->consoleColor();
            $statusLabel = match ($worst->value) {
                'critical' => 'FAIL — Critical issues detected',
                'warning' => 'WARN — Warnings present',
                'optimization' => 'OK — Optimizations available',
                default => 'PASS — No issues detected',
            };
        }

        $command->line(sprintf('<fg=%s>=========================================================</>', $color));
        $command->line(sprintf('<fg=%s;options=bold>  PERFORMANCE ADVISORY REPORT</>', $color));
        $command->line(sprintf('<fg=%s>=========================================================</>', $color));
        $command->newLine();
        $command->line(sprintf('  Status:     <fg=%s;options=bold>%s</>', $color, $statusLabel));

        // Show confidence-adjusted grade; indicate if different from base
        if ($effectiveGrade !== $report->grade) {
            $command->line(sprintf(
                '  Grade:      <fg=%s;options=bold>%s</> (%.1f / 100) <fg=yellow>[base: %s (%.1f) — capped by confidence/findings]</>',
                $color, $effectiveGrade, $effectiveScore, $report->grade, $report->compositeScore,
            ));
        } else {
            $command->line(sprintf('  Grade:      <fg=%s;options=bold>%s</> (%.1f / 100)', $color, $effectiveGrade, $effectiveScore));
        }

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
        $command->line(sprintf('  Access Type:           <fg=white;options=bold>%s</>', strtoupper($metrics['mysql_access_type'] ?? 'N/A')));
        if ($metrics['is_intentional_scan'] ?? false) {
            $command->line('  Scan Class:            <fg=green;options=bold>Intentional Full Dataset Retrieval</>');
        }
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

        if ($scores['dataset_dampened'] ?? false) {
            $command->line('  <fg=cyan>Score dampened: large unbounded result set</>');
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

        $complexityValue = $scalability['complexity'] ?? '';
        $isStable = $complexityValue === 'O(1)';

        $command->line('<fg=yellow>  Scalability Estimation:</>');
        $command->line('  '.str_repeat('-', 70));
        $command->line(sprintf('  Table Size (rows):     %s', number_format($scalability['current_rows'] ?? 0)));
        $command->line(sprintf('  Risk:                  <fg=%s;options=bold>%s</>', $riskColor, $risk));

        if ($isStable) {
            $command->line('  Scalability:           <fg=green;options=bold>Stable</> (constant time at any table size)');
        }

        if ($scalability['is_intentional_scan'] ?? false) {
            $command->line('  Classification:        <fg=green;options=bold>EXPECTED_LINEAR_SCALING</>');
            $command->line('  Note:                  <fg=cyan>Full dataset retrieval — O(n) is the optimal plan.</>');
        }

        $linearSubtype = $scalability['linear_subtype'] ?? null;
        if ($linearSubtype !== null) {
            $subtypeLabels = [
                'EXPORT_LINEAR' => 'Full dataset export',
                'ANALYTICAL_LINEAR' => 'Aggregation/grouping scan',
                'INDEX_MISSED_LINEAR' => 'Missing index — table scan where index would help',
                'PATHOLOGICAL_LINEAR' => 'Complex linear scan',
            ];
            $subtypeColor = $linearSubtype === 'INDEX_MISSED_LINEAR' ? 'red' : 'cyan';
            $command->line(sprintf('  Linear Subtype:        <fg=%s>%s</>', $subtypeColor, $subtypeLabels[$linearSubtype] ?? $linearSubtype));
        }

        $projections = $scalability['projections'] ?? [];
        foreach ($projections as $p) {
            $confidenceNote = ($p['confidence'] ?? 'high') !== 'high'
                ? sprintf(' — %s confidence', $p['confidence'])
                : '';
            $command->line(sprintf(
                '    at %sM:  %s  (projected %.1fms%s)',
                number_format(($p['target_rows'] ?? 0) / 1_000_000),
                $p['label'] ?? '',
                $p['projected_time_ms'] ?? 0,
                $confidenceNote,
            ));
        }

        $limitSensitivity = $scalability['limit_sensitivity'] ?? [];
        if (! empty($limitSensitivity) && ! $isStable) {
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

    private function renderCardinalityDrift(Command $command, array $drift): void
    {
        $command->line('<fg=yellow>  Cardinality Drift:</>');
        $command->line('  '.str_repeat('-', 70));
        $command->line(sprintf('  Composite Drift Score: <fg=white;options=bold>%.2f</>', $drift['composite_drift_score'] ?? 0));

        $perTable = $drift['per_table'] ?? [];
        if (! empty($perTable)) {
            $command->line('  Per-Table Analysis:');
            foreach ($perTable as $table => $data) {
                $severity = $data['severity'] ?? 'info';
                $color = match ($severity) {
                    'critical' => 'red',
                    'warning' => 'yellow',
                    default => 'gray',
                };
                $command->line(sprintf(
                    '    <fg=%s>%-20s</> drift: %.0f%%  direction: %s  (est: %s, actual: %s)',
                    $color,
                    $table,
                    ($data['drift_ratio'] ?? 0) * 100,
                    $data['drift_direction'] ?? 'unknown',
                    number_format($data['estimated_rows'] ?? 0),
                    number_format($data['actual_rows'] ?? 0),
                ));
            }
        }

        $needsAnalyze = $drift['tables_needing_analyze'] ?? [];
        if (! empty($needsAnalyze)) {
            $command->line(sprintf('  Tables needing ANALYZE: <fg=yellow>%s</>', implode(', ', $needsAnalyze)));
        }

        $command->line('  '.str_repeat('-', 70));
        $command->newLine();
    }

    private function renderConfidence(Command $command, array $confidence): void
    {
        $overall = $confidence['overall'] ?? 0;
        $label = $confidence['label'] ?? 'unknown';
        $color = match ($label) {
            'high' => 'green',
            'moderate' => 'yellow',
            'low' => 'red',
            default => 'gray',
        };

        $barLength = (int) round($overall * 20);
        $bar = str_repeat('|', $barLength).str_repeat('.', 20 - $barLength);

        $command->line('<fg=yellow>  Analysis Confidence:</>');
        $command->line('  '.str_repeat('-', 70));
        $command->line(sprintf('  Score: <fg=%s;options=bold>%.0f%%</> [%s] %s', $color, $overall * 100, $bar, strtoupper($label)));

        $factors = $confidence['factors'] ?? [];
        foreach ($factors as $factor) {
            $command->line(sprintf(
                '    %-22s %.0f%% (weight: %.0f%%) — %s',
                str_replace('_', ' ', $factor['name'] ?? ''),
                ($factor['score'] ?? 0) * 100,
                ($factor['weight'] ?? 0) * 100,
                $factor['note'] ?? '',
            ));
        }

        $command->line('  '.str_repeat('-', 70));
        $command->newLine();
    }

    private function renderMemoryPressure(Command $command, array $pressure): void
    {
        $risk = $pressure['memory_risk'] ?? 'low';
        $riskColor = match ($risk) {
            'high' => 'red',
            'moderate' => 'yellow',
            default => 'green',
        };

        $command->line('<fg=yellow>  Memory Pressure:</>');
        $command->line('  '.str_repeat('-', 70));
        $command->line(sprintf('  Risk: <fg=%s;options=bold>%s</>', $riskColor, strtoupper($risk)));
        $command->line(sprintf('  Total Estimated: %s', $this->formatMemoryBytes($pressure['total_estimated_bytes'] ?? 0)));
        $command->line(sprintf('  Buffer Pool Pressure: %.1f%%', ($pressure['buffer_pool_pressure'] ?? 0) * 100));

        $categories = $pressure['categories'] ?? [];
        if (! empty($categories)) {
            $command->line('  Breakdown:');
            foreach ($categories as $name => $bytes) {
                $label = str_replace('_', ' ', $name);
                $command->line(sprintf('    %-30s %s', ucfirst($label), $this->formatMemoryBytes($bytes)));
            }
        }

        $components = $pressure['components'] ?? [];
        $activeComponents = array_filter($components, fn ($bytes) => $bytes > 0);
        if (! empty($activeComponents)) {
            $command->line('  Detail:');
            foreach ($activeComponents as $name => $bytes) {
                $command->line(sprintf('    %-30s %s', str_replace('_', ' ', $name), $this->formatMemoryBytes($bytes)));
            }
        }

        // Network pressure classification
        $networkPressure = $pressure['network_pressure'] ?? 'LOW';
        if ($networkPressure !== 'LOW') {
            $npColor = match ($networkPressure) {
                'CRITICAL' => 'red',
                'HIGH' => 'red',
                'MODERATE' => 'yellow',
                default => 'green',
            };
            $command->line(sprintf('  Network Pressure:     <fg=%s;options=bold>%s</>', $npColor, $networkPressure));
        }

        // Network transfer highlight
        $networkTransfer = $categories['network_transfer_estimate'] ?? 0;
        if ($networkTransfer > 52428800) { // > 50MB
            $command->line(sprintf('  <fg=yellow>Network Transfer:        %s (consider streaming/pagination)</>', $this->formatMemoryBytes($networkTransfer)));
        }

        // Concurrency-adjusted totals
        $concurrencyAdjusted = $pressure['concurrency_adjusted'] ?? null;
        if ($concurrencyAdjusted !== null && ($concurrencyAdjusted['concurrent_sessions'] ?? 1) > 1) {
            $sessions = $concurrencyAdjusted['concurrent_sessions'];
            $command->line(sprintf(
                '  Concurrency (%dx):     %s execution memory, %s network transfer',
                $sessions,
                $this->formatMemoryBytes($concurrencyAdjusted['concurrent_execution_memory'] ?? 0),
                $this->formatMemoryBytes($concurrencyAdjusted['concurrent_network_transfer'] ?? 0),
            ));
        }

        $command->line('  '.str_repeat('-', 70));
        $command->newLine();
    }

    private function renderConcurrencyRisk(Command $command, array $concurrency): void
    {
        $lockScope = $concurrency['lock_scope'] ?? 'unknown';

        $command->line('<fg=yellow>  Concurrency Risk:</>');
        $command->line('  '.str_repeat('-', 70));

        if ($lockScope === 'none') {
            $command->line('  Lock Scope:      <fg=green;options=bold>NONE</> (read-only, MVCC consistent read)');
            $command->line('  Deadlock Risk:   <fg=green;options=bold>NONE</>');
            $command->line('  Contention:      0.00');
        } else {
            $riskLabel = $concurrency['deadlock_risk_label'] ?? 'low';
            $riskColor = match ($riskLabel) {
                'high' => 'red',
                'moderate' => 'yellow',
                default => 'green',
            };

            $command->line(sprintf('  Lock Scope:      <fg=white;options=bold>%s</>', strtoupper($lockScope)));
            $command->line(sprintf('  Deadlock Risk:   <fg=%s;options=bold>%s</> (%.1f)', $riskColor, strtoupper($riskLabel), $concurrency['deadlock_risk'] ?? 0));
            $command->line(sprintf('  Contention:      %.2f', $concurrency['contention_score'] ?? 0));
        }

        if (! empty($concurrency['isolation_impact'])) {
            $command->line(sprintf('  Isolation:       %s', $concurrency['isolation_impact']));
        }

        $command->line('  '.str_repeat('-', 70));
        $command->newLine();
    }

    private function renderRegression(Command $command, array $regression): void
    {
        if (! ($regression['has_baseline'] ?? false)) {
            $command->line('<fg=yellow>  Regression Analysis:</>');
            $command->line('  '.str_repeat('-', 70));
            $command->line('  <fg=gray>No baseline data yet. This query will be tracked for future comparisons.</>');
            $command->line('  '.str_repeat('-', 70));
            $command->newLine();

            return;
        }

        $trend = $regression['trend'] ?? 'stable';
        $trendColor = match ($trend) {
            'degrading' => 'red',
            'improving' => 'green',
            default => 'yellow',
        };

        $command->line('<fg=yellow>  Regression Analysis:</>');
        $command->line('  '.str_repeat('-', 70));
        $command->line(sprintf('  Baseline Count:  %d snapshots', $regression['baseline_count'] ?? 0));
        $command->line(sprintf('  Trend:           <fg=%s;options=bold>%s</>', $trendColor, strtoupper($trend)));

        $regressions = $regression['regressions'] ?? [];
        if (! empty($regressions)) {
            $command->line('  Regressions:');
            foreach ($regressions as $r) {
                $command->line(sprintf(
                    '    <fg=red>[%s]</> %s: %.1f -> %.1f (%+.0f%%)',
                    strtoupper($r['severity'] ?? 'warning'),
                    $r['metric'] ?? '',
                    $r['baseline_value'] ?? 0,
                    $r['current_value'] ?? 0,
                    $r['change_pct'] ?? 0,
                ));
            }
        }

        $improvements = $regression['improvements'] ?? [];
        if (! empty($improvements)) {
            $command->line('  Improvements:');
            foreach ($improvements as $imp) {
                $command->line(sprintf(
                    '    <fg=green>[+]</> %s: %.1f -> %.1f (%+.0f%%)',
                    $imp['metric'] ?? '',
                    $imp['baseline_value'] ?? 0,
                    $imp['current_value'] ?? 0,
                    $imp['change_pct'] ?? 0,
                ));
            }
        }

        $command->line('  '.str_repeat('-', 70));
        $command->newLine();
    }

    private function renderHypotheticalIndexes(Command $command, array $hypothetical): void
    {
        if (! ($hypothetical['enabled'] ?? false)) {
            return;
        }

        $simulations = $hypothetical['simulations'] ?? [];
        if (empty($simulations)) {
            return;
        }

        $command->line('<fg=yellow>  Hypothetical Index Simulation:</>');
        $command->line('  '.str_repeat('-', 70));

        foreach ($simulations as $i => $sim) {
            $improvement = $sim['improvement'] ?? 'none';
            $color = match ($improvement) {
                'significant' => 'green',
                'moderate' => 'yellow',
                'marginal' => 'gray',
                default => 'red',
            };
            $validated = ($sim['validated'] ?? false) ? 'YES' : 'NO';

            $command->line(sprintf('  %d. %s', $i + 1, $sim['index_ddl'] ?? ''));
            $command->line(sprintf(
                '     Before: %s (%s rows)  ->  After: %s (%s rows)',
                $sim['before']['access_type'] ?? '?',
                number_format($sim['before']['rows'] ?? 0),
                $sim['after']['access_type'] ?? '?',
                number_format($sim['after']['rows'] ?? 0),
            ));
            $command->line(sprintf(
                '     Improvement: <fg=%s;options=bold>%s</>  Validated: %s',
                $color,
                strtoupper($improvement),
                $validated,
            ));
            if (! empty($sim['notes'])) {
                $command->line(sprintf('     Notes: %s', $sim['notes']));
            }
        }

        $best = $hypothetical['best_recommendation'] ?? null;
        if ($best !== null) {
            $command->line(sprintf('  Best: <fg=green;options=bold>%s</>', $best));
        }

        $command->line('  '.str_repeat('-', 70));
        $command->newLine();
    }

    private function renderWorkload(Command $command, array $workload): void
    {
        $frequency = $workload['query_frequency'] ?? 0;
        $isFrequent = $workload['is_frequent'] ?? false;
        $patterns = $workload['patterns'] ?? [];

        $command->line('<fg=yellow>  Workload Analysis:</>');
        $command->line('  '.str_repeat('-', 70));
        $command->line(sprintf('  Query Frequency:       %d snapshots%s', $frequency, $isFrequent ? ' <fg=yellow>(frequent)</>' : ''));

        if (empty($patterns)) {
            $command->line('  Patterns:              <fg=green>None detected</>');
        } else {
            foreach ($patterns as $pattern) {
                $type = $pattern['type'] ?? 'unknown';
                $severity = $pattern['severity'] ?? 'info';
                $occurrences = $pattern['occurrences'] ?? 0;
                $color = match ($severity) {
                    'critical' => 'red',
                    'warning' => 'yellow',
                    default => 'gray',
                };
                $command->line(sprintf('  <fg=%s>[%s]</> %s (%d occurrences)', $color, strtoupper($severity), $type, $occurrences));
            }
        }

        $command->line('  '.str_repeat('-', 70));
        $command->newLine();
    }

    private function formatMemoryBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return sprintf('%.1f GB', $bytes / 1073741824);
        }
        if ($bytes >= 1048576) {
            return sprintf('%.1f MB', $bytes / 1048576);
        }
        if ($bytes >= 1024) {
            return sprintf('%.1f KB', $bytes / 1024);
        }

        return sprintf('%d B', $bytes);
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
