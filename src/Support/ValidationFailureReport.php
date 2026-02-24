<?php

declare(strict_types=1);

namespace QuerySentinel\Support;

/**
 * Structured failure report when validation or EXPLAIN fails.
 *
 * No scoring. No scalability. No recommendations. No performance section.
 */
final readonly class ValidationFailureReport
{
    /**
     * @param  string[]  $recommendations
     */
    public function __construct(
        public string $status,
        public string $failureStage,
        public string $detailedError,
        public ?string $sqlstateCode = null,
        public ?int $lineNumber = null,
        public array $recommendations = [],
        public ?string $suggestion = null,
        public ?string $missingTable = null,
        public ?string $missingColumn = null,
        public ?string $database = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'grade' => 'N/A',
            'analysis' => 'Aborted',
            'failure_stage' => $this->failureStage,
            'detailed_error' => $this->detailedError,
            'sqlstate_code' => $this->sqlstateCode,
            'line_number' => $this->lineNumber,
            'recommendations' => $this->recommendations,
            'suggestion' => $this->suggestion,
            'missing_table' => $this->missingTable,
            'missing_column' => $this->missingColumn,
            'database' => $this->database,
        ];
    }

    public function toJson(int $flags = 0): string
    {
        return json_encode($this->toArray(), $flags | JSON_THROW_ON_ERROR);
    }
}
