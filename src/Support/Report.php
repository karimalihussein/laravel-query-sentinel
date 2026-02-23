<?php

declare(strict_types=1);

namespace QuerySentinel\Support;

final readonly class Report
{
    /**
     * @param  array<int, string>  $recommendations
     * @param  array<string, mixed>  $scalability
     * @param  string  $mode  Analysis mode: 'sql', 'builder', or 'profiler'
     */
    public function __construct(
        public Result $result,
        public string $grade,
        public bool $passed,
        public string $summary,
        public array $recommendations,
        public float $compositeScore,
        public \DateTimeImmutable $analyzedAt,
        public array $scalability = [],
        public string $mode = 'sql',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'result' => $this->result->toArray(),
            'grade' => $this->grade,
            'passed' => $this->passed,
            'summary' => $this->summary,
            'composite_score' => $this->compositeScore,
            'recommendations' => $this->recommendations,
            'scalability' => $this->scalability,
            'analyzed_at' => $this->analyzedAt->format('c'),
        ];
    }

    public function toJson(int $flags = 0): string
    {
        return json_encode($this->toArray(), $flags | JSON_THROW_ON_ERROR);
    }

    /**
     * Count findings by severity level.
     *
     * @return array<string, int>
     */
    public function findingCounts(): array
    {
        $counts = ['critical' => 0, 'warning' => 0, 'optimization' => 0, 'info' => 0];

        foreach ($this->result->findings as $finding) {
            $severity = $finding['severity'] ?? 'info';
            $counts[$severity] = ($counts[$severity] ?? 0) + 1;
        }

        return $counts;
    }
}
