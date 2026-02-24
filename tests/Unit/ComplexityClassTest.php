<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QuerySentinel\Enums\ComplexityClass;

final class ComplexityClassTest extends TestCase
{
    public function test_all_cases_exist(): void
    {
        $cases = ComplexityClass::cases();

        $this->assertCount(6, $cases);
        $this->assertContains(ComplexityClass::Constant, $cases);
        $this->assertContains(ComplexityClass::Logarithmic, $cases);
        $this->assertContains(ComplexityClass::LogRange, $cases);
        $this->assertContains(ComplexityClass::Linear, $cases);
        $this->assertContains(ComplexityClass::Linearithmic, $cases);
        $this->assertContains(ComplexityClass::Quadratic, $cases);
    }

    public function test_case_values(): void
    {
        $this->assertSame('O(1)', ComplexityClass::Constant->value);
        $this->assertSame('O(log n)', ComplexityClass::Logarithmic->value);
        $this->assertSame('O(log n + k)', ComplexityClass::LogRange->value);
        $this->assertSame('O(n)', ComplexityClass::Linear->value);
        $this->assertSame('O(n log n)', ComplexityClass::Linearithmic->value);
        $this->assertSame('O(n²)', ComplexityClass::Quadratic->value);
    }

    public function test_try_from_all_valid_values(): void
    {
        $this->assertSame(ComplexityClass::Constant, ComplexityClass::tryFrom('O(1)'));
        $this->assertSame(ComplexityClass::Logarithmic, ComplexityClass::tryFrom('O(log n)'));
        $this->assertSame(ComplexityClass::LogRange, ComplexityClass::tryFrom('O(log n + k)'));
        $this->assertSame(ComplexityClass::Linear, ComplexityClass::tryFrom('O(n)'));
        $this->assertSame(ComplexityClass::Linearithmic, ComplexityClass::tryFrom('O(n log n)'));
        $this->assertSame(ComplexityClass::Quadratic, ComplexityClass::tryFrom('O(n²)'));
    }

    public function test_try_from_invalid_returns_null(): void
    {
        $this->assertNull(ComplexityClass::tryFrom('O(unknown)'));
        $this->assertNull(ComplexityClass::tryFrom(''));
        $this->assertNull(ComplexityClass::tryFrom('O(limit)'));
        $this->assertNull(ComplexityClass::tryFrom('O(range)'));
    }

    public function test_labels_are_non_empty_strings(): void
    {
        foreach (ComplexityClass::cases() as $case) {
            $this->assertNotEmpty($case->label());
            $this->assertIsString($case->label());
        }
    }

    public function test_label_descriptions(): void
    {
        $this->assertStringContainsString('Constant', ComplexityClass::Constant->label());
        $this->assertStringContainsString('logarithmic', ComplexityClass::Logarithmic->label());
        $this->assertStringContainsString('range', ComplexityClass::LogRange->label());
        $this->assertStringContainsString('linear', strtolower(ComplexityClass::Linear->label()));
        $this->assertStringContainsString('Sort', ComplexityClass::Linearithmic->label());
        $this->assertStringContainsString('quadratic', strtolower(ComplexityClass::Quadratic->label()));
    }

    public function test_risk_levels(): void
    {
        $this->assertSame('LOW', ComplexityClass::Constant->riskLevel());
        $this->assertSame('LOW', ComplexityClass::Logarithmic->riskLevel());
        $this->assertSame('LOW', ComplexityClass::LogRange->riskLevel());
        $this->assertSame('MEDIUM', ComplexityClass::Linear->riskLevel());
        $this->assertSame('MEDIUM', ComplexityClass::Linearithmic->riskLevel());
        $this->assertSame('HIGH', ComplexityClass::Quadratic->riskLevel());
    }

    public function test_ordinal_strictly_increasing(): void
    {
        $expected = [
            ComplexityClass::Constant,
            ComplexityClass::Logarithmic,
            ComplexityClass::LogRange,
            ComplexityClass::Linear,
            ComplexityClass::Linearithmic,
            ComplexityClass::Quadratic,
        ];

        for ($i = 0; $i < count($expected) - 1; $i++) {
            $this->assertLessThan(
                $expected[$i + 1]->ordinal(),
                $expected[$i]->ordinal(),
                sprintf('%s should have lower ordinal than %s', $expected[$i]->name, $expected[$i + 1]->name),
            );
        }
    }

    public function test_ordinal_values(): void
    {
        $this->assertSame(0, ComplexityClass::Constant->ordinal());
        $this->assertSame(1, ComplexityClass::Logarithmic->ordinal());
        $this->assertSame(2, ComplexityClass::LogRange->ordinal());
        $this->assertSame(3, ComplexityClass::Linear->ordinal());
        $this->assertSame(4, ComplexityClass::Linearithmic->ordinal());
        $this->assertSame(5, ComplexityClass::Quadratic->ordinal());
    }
}
