<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QuerySentinel\Enums\Severity;
use QuerySentinel\Support\DiagnosticReport;
use QuerySentinel\Support\EngineConsistencyValidator;
use QuerySentinel\Support\Finding;
use QuerySentinel\Support\Report;
use QuerySentinel\Support\Result;

/**
 * Tests for confidence-aware grade capping (Problem 2)
 * and parsing/grade consistency validation (Problem 5).
 */
final class ConfidenceGradeCapTest extends TestCase
{
    // ---------------------------------------------------------------
    // effectiveGrade() — confidence caps
    // ---------------------------------------------------------------

    public function test_low_confidence_caps_grade_at_c(): void
    {
        $diagnostic = $this->createDiagnostic(
            compositeScore: 100.0,
            grade: 'A',
            confidence: ['overall' => 0.45, 'label' => 'low'],
        );

        $this->assertSame('C', $diagnostic->effectiveGrade());
    }

    public function test_moderate_confidence_caps_grade_at_b(): void
    {
        $diagnostic = $this->createDiagnostic(
            compositeScore: 95.0,
            grade: 'A',
            confidence: ['overall' => 0.65, 'label' => 'moderate'],
        );

        $this->assertSame('B', $diagnostic->effectiveGrade());
    }

    public function test_high_confidence_does_not_cap(): void
    {
        $diagnostic = $this->createDiagnostic(
            compositeScore: 95.0,
            grade: 'A',
            confidence: ['overall' => 0.85, 'label' => 'high'],
        );

        $this->assertSame('A', $diagnostic->effectiveGrade());
    }

    public function test_critical_findings_cap_grade_at_b(): void
    {
        $diagnostic = $this->createDiagnostic(
            compositeScore: 95.0,
            grade: 'A',
            confidence: ['overall' => 0.90, 'label' => 'high'],
            findings: [
                new Finding(Severity::Critical, 'cardinality_drift', 'Cardinality drift', 'High drift.'),
            ],
        );

        $this->assertSame('B', $diagnostic->effectiveGrade());
    }

    public function test_critical_with_low_confidence_caps_at_c(): void
    {
        $diagnostic = $this->createDiagnostic(
            compositeScore: 100.0,
            grade: 'A',
            confidence: ['overall' => 0.40, 'label' => 'low'],
            findings: [
                new Finding(Severity::Critical, 'cardinality_drift', 'Cardinality drift', 'High drift.'),
            ],
        );

        // Both critical cap (B) and low confidence cap (C) → C wins
        $this->assertSame('C', $diagnostic->effectiveGrade());
    }

    public function test_low_grade_not_affected_by_caps(): void
    {
        $diagnostic = $this->createDiagnostic(
            compositeScore: 30.0,
            grade: 'D',
            confidence: ['overall' => 0.40, 'label' => 'low'],
        );

        // D is already below all caps
        $this->assertSame('D', $diagnostic->effectiveGrade());
    }

    // ---------------------------------------------------------------
    // effectiveCompositeScore() — score caps
    // ---------------------------------------------------------------

    public function test_low_confidence_caps_score_at_50(): void
    {
        $diagnostic = $this->createDiagnostic(
            compositeScore: 100.0,
            grade: 'A',
            confidence: ['overall' => 0.45, 'label' => 'low'],
        );

        $this->assertSame(50.0, $diagnostic->effectiveCompositeScore());
    }

    public function test_moderate_confidence_caps_score_at_75(): void
    {
        $diagnostic = $this->createDiagnostic(
            compositeScore: 95.0,
            grade: 'A',
            confidence: ['overall' => 0.65, 'label' => 'moderate'],
        );

        $this->assertSame(75.0, $diagnostic->effectiveCompositeScore());
    }

    public function test_critical_findings_cap_score_at_75(): void
    {
        $diagnostic = $this->createDiagnostic(
            compositeScore: 100.0,
            grade: 'A',
            confidence: ['overall' => 0.90, 'label' => 'high'],
            findings: [
                new Finding(Severity::Critical, 'cardinality_drift', 'Cardinality drift', 'High drift.'),
            ],
        );

        $this->assertSame(75.0, $diagnostic->effectiveCompositeScore());
    }

    public function test_high_confidence_no_criticals_no_cap(): void
    {
        $diagnostic = $this->createDiagnostic(
            compositeScore: 95.0,
            grade: 'A',
            confidence: ['overall' => 0.85, 'label' => 'high'],
        );

        $this->assertSame(95.0, $diagnostic->effectiveCompositeScore());
    }

    public function test_no_confidence_data_defaults_to_no_cap(): void
    {
        $diagnostic = $this->createDiagnostic(
            compositeScore: 95.0,
            grade: 'A',
            confidence: null,
        );

        $this->assertSame(95.0, $diagnostic->effectiveCompositeScore());
        $this->assertSame('A', $diagnostic->effectiveGrade());
    }

    // ---------------------------------------------------------------
    // EngineConsistencyValidator — Rule 9: parsing_valid
    // ---------------------------------------------------------------

    public function test_validator_detects_parsing_invalid_with_nonzero_time(): void
    {
        $validator = new EngineConsistencyValidator;
        $result = $validator->validate(
            [
                'primary_access_type' => 'table_scan',
                'has_table_scan' => true,
                'is_index_backed' => false,
                'parsing_valid' => false,
                'execution_time_ms' => 5.0,
            ],
            [],
        );

        $this->assertFalse($result['valid']);
        $hasRule9 = false;
        foreach ($result['violations'] as $v) {
            if (str_contains($v, 'parsing_valid=false')) {
                $hasRule9 = true;
            }
        }
        $this->assertTrue($hasRule9);
    }

    public function test_validator_allows_parsing_invalid_with_zero_time(): void
    {
        $validator = new EngineConsistencyValidator;
        $result = $validator->validate(
            [
                'primary_access_type' => 'table_scan',
                'has_table_scan' => true,
                'is_index_backed' => false,
                'parsing_valid' => false,
                'execution_time_ms' => 0.0,
            ],
            [],
        );

        $hasRule9 = false;
        foreach ($result['violations'] as $v) {
            if (str_contains($v, 'parsing_valid=false')) {
                $hasRule9 = true;
            }
        }
        $this->assertFalse($hasRule9);
    }

    public function test_validator_allows_parsing_valid_with_nonzero_time(): void
    {
        $validator = new EngineConsistencyValidator;
        $result = $validator->validate(
            [
                'primary_access_type' => 'index_lookup',
                'is_index_backed' => true,
                'parsing_valid' => true,
                'execution_time_ms' => 5.0,
            ],
            [],
        );

        $hasRule9 = false;
        foreach ($result['violations'] as $v) {
            if (str_contains($v, 'parsing_valid')) {
                $hasRule9 = true;
            }
        }
        $this->assertFalse($hasRule9);
    }

    // ---------------------------------------------------------------
    // Helper
    // ---------------------------------------------------------------

    /**
     * @param  Finding[]  $findings
     * @param  array<string, mixed>|null  $confidence
     */
    private function createDiagnostic(
        float $compositeScore,
        string $grade,
        ?array $confidence,
        array $findings = [],
    ): DiagnosticReport {
        $result = new Result(
            sql: 'SELECT 1',
            driver: 'mysql',
            explainRows: [],
            plan: '-> Table scan on t  (cost=1 rows=1) (actual time=0.01..0.01 rows=1 loops=1)',
            metrics: [],
            scores: ['composite_score' => $compositeScore, 'grade' => $grade, 'breakdown' => []],
            findings: [],
            executionTimeMs: 0.01,
        );

        $report = new Report(
            result: $result,
            grade: $grade,
            passed: empty(array_filter($findings, fn (Finding $f) => $f->severity === Severity::Critical)),
            summary: 'test',
            recommendations: [],
            compositeScore: $compositeScore,
            analyzedAt: new \DateTimeImmutable,
        );

        return new DiagnosticReport(
            report: $report,
            findings: $findings,
            confidence: $confidence,
        );
    }
}
