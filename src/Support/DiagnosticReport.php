<?php

declare(strict_types=1);

namespace QuerySentinel\Support;

use QuerySentinel\Enums\Severity;

/**
 * Top-level container for deep diagnostic analysis.
 *
 * Wraps the base Report with additional deep analysis data from the
 * 6 diagnostic analyzers, structured findings, and "Explain Why" insights.
 */
final readonly class DiagnosticReport
{
    /**
     * @param  Finding[]  $findings
     * @param  array<string, mixed>|null  $indexAnalysis
     * @param  array<string, mixed>|null  $joinAnalysis
     * @param  array<string, mixed>|null  $stabilityAnalysis
     * @param  array<string, mixed>|null  $safetyAnalysis
     */
    public function __construct(
        public Report $report,
        public array $findings,
        public ?EnvironmentContext $environment = null,
        public ?ExecutionProfile $executionProfile = null,
        public ?array $indexAnalysis = null,
        public ?array $joinAnalysis = null,
        public ?array $stabilityAnalysis = null,
        public ?array $safetyAnalysis = null,
    ) {}

    /**
     * Filter findings by category.
     *
     * @return Finding[]
     */
    public function findingsByCategory(string $category): array
    {
        return array_values(
            array_filter($this->findings, fn (Finding $f) => $f->category === $category)
        );
    }

    /**
     * Count findings by severity level.
     *
     * @return array{critical: int, warning: int, optimization: int, info: int}
     */
    public function findingCounts(): array
    {
        $counts = ['critical' => 0, 'warning' => 0, 'optimization' => 0, 'info' => 0];

        foreach ($this->findings as $finding) {
            $counts[$finding->severity->value]++;
        }

        return $counts;
    }

    /**
     * Determine the worst (highest priority) severity across all findings.
     */
    public function worstSeverity(): Severity
    {
        $worst = Severity::Info;

        foreach ($this->findings as $finding) {
            if ($finding->severity->priority() < $worst->priority()) {
                $worst = $finding->severity;
            }
        }

        return $worst;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = $this->report->toArray();

        $data['diagnostic'] = [
            'findings' => array_map(fn (Finding $f) => $f->toArray(), $this->findings),
            'finding_counts' => $this->findingCounts(),
            'worst_severity' => $this->worstSeverity()->value,
        ];

        if ($this->environment !== null) {
            $data['diagnostic']['environment'] = $this->environment->toArray();
        }

        if ($this->executionProfile !== null) {
            $data['diagnostic']['execution_profile'] = $this->executionProfile->toArray();
        }

        if ($this->indexAnalysis !== null) {
            $data['diagnostic']['index_analysis'] = $this->indexAnalysis;
        }

        if ($this->joinAnalysis !== null) {
            $data['diagnostic']['join_analysis'] = $this->joinAnalysis;
        }

        if ($this->stabilityAnalysis !== null) {
            $data['diagnostic']['plan_stability'] = $this->stabilityAnalysis;
        }

        if ($this->safetyAnalysis !== null) {
            $data['diagnostic']['regression_safety'] = $this->safetyAnalysis;
        }

        return $data;
    }

    public function toJson(int $flags = 0): string
    {
        return (string) json_encode($this->toArray(), $flags);
    }
}
