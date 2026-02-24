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
     * @param  array<string, mixed>|null  $cardinalityDrift
     * @param  array<int, array<string, mixed>>|null  $antiPatterns
     * @param  array<string, mixed>|null  $indexSynthesis
     * @param  array<string, mixed>|null  $confidence
     * @param  array<string, mixed>|null  $concurrencyRisk
     * @param  array<string, mixed>|null  $memoryPressure
     * @param  array<string, mixed>|null  $regression
     * @param  array<string, mixed>|null  $hypotheticalIndexes
     * @param  array<string, mixed>|null  $workload
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
        public ?array $cardinalityDrift = null,
        public ?array $antiPatterns = null,
        public ?array $indexSynthesis = null,
        public ?array $confidence = null,
        public ?array $concurrencyRisk = null,
        public ?array $memoryPressure = null,
        public ?array $regression = null,
        public ?array $hypotheticalIndexes = null,
        public ?array $workload = null,
    ) {}

    /**
     * Confidence-adjusted grade: caps grade when confidence is too low
     * or critical findings are present.
     *
     * - Confidence < 50%: grade capped at C
     * - Confidence < 70%: grade capped at B
     * - Critical findings present: grade capped at B
     */
    public function effectiveGrade(): string
    {
        $baseGrade = $this->report->grade;
        $confidenceOverall = $this->confidence['overall'] ?? 1.0;
        $counts = $this->findingCounts();

        $grade = $baseGrade;

        // Critical findings present → cap at B
        if ($counts['critical'] > 0 && in_array($grade, ['A+', 'A'], true)) {
            $grade = 'B';
        }

        // Low confidence → cap at C
        if ($confidenceOverall < 0.5 && in_array($grade, ['A+', 'A', 'B'], true)) {
            $grade = 'C';
        }

        // Moderate confidence → cap at B
        if ($confidenceOverall >= 0.5 && $confidenceOverall < 0.7 && in_array($grade, ['A+', 'A'], true)) {
            $grade = 'B';
        }

        return $grade;
    }

    /**
     * Confidence-adjusted composite score: caps score when confidence is too low.
     */
    public function effectiveCompositeScore(): float
    {
        $baseScore = $this->report->compositeScore;
        $confidenceOverall = $this->confidence['overall'] ?? 1.0;
        $counts = $this->findingCounts();

        $score = $baseScore;

        // Critical findings present → cap at 75
        if ($counts['critical'] > 0) {
            $score = min($score, 75.0);
        }

        // Low confidence → cap at 50
        if ($confidenceOverall < 0.5) {
            $score = min($score, 50.0);
        }

        // Moderate confidence → cap at 75
        if ($confidenceOverall >= 0.5 && $confidenceOverall < 0.7) {
            $score = min($score, 75.0);
        }

        return $score;
    }

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

        if ($this->cardinalityDrift !== null) {
            $data['diagnostic']['cardinality_drift'] = $this->cardinalityDrift;
        }

        if ($this->antiPatterns !== null) {
            $data['diagnostic']['anti_patterns'] = $this->antiPatterns;
        }

        if ($this->indexSynthesis !== null) {
            $data['diagnostic']['index_synthesis'] = $this->indexSynthesis;
        }

        if ($this->confidence !== null) {
            $data['diagnostic']['confidence'] = $this->confidence;
        }

        if ($this->concurrencyRisk !== null) {
            $data['diagnostic']['concurrency_risk'] = $this->concurrencyRisk;
        }

        if ($this->memoryPressure !== null) {
            $data['diagnostic']['memory_pressure'] = $this->memoryPressure;
        }

        if ($this->regression !== null) {
            $data['diagnostic']['regression'] = $this->regression;
        }

        if ($this->hypotheticalIndexes !== null) {
            $data['diagnostic']['hypothetical_indexes'] = $this->hypotheticalIndexes;
        }

        if ($this->workload !== null) {
            $data['diagnostic']['workload'] = $this->workload;
        }

        return $data;
    }

    public function toJson(int $flags = 0): string
    {
        return (string) json_encode($this->toArray(), $flags);
    }
}
