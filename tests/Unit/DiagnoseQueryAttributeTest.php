<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QuerySentinel\Attributes\DiagnoseQuery;

final class DiagnoseQueryAttributeTest extends TestCase
{
    public function test_default_values(): void
    {
        $attribute = new DiagnoseQuery;

        $this->assertSame('', $attribute->label);
        $this->assertSame('', $attribute->description);
    }

    public function test_custom_label(): void
    {
        $attribute = new DiagnoseQuery(label: 'Slow lead search');

        $this->assertSame('Slow lead search', $attribute->label);
        $this->assertSame('', $attribute->description);
    }

    public function test_custom_label_and_description(): void
    {
        $attribute = new DiagnoseQuery(
            label: 'Slow lead search',
            description: 'Lead search with all filters applied',
        );

        $this->assertSame('Slow lead search', $attribute->label);
        $this->assertSame('Lead search with all filters applied', $attribute->description);
    }

    public function test_attribute_is_discoverable_via_reflection(): void
    {
        $reflection = new \ReflectionMethod(DiagnoseQueryTestFixture::class, 'annotatedMethod');
        $attributes = $reflection->getAttributes(DiagnoseQuery::class);

        $this->assertCount(1, $attributes);

        $instance = $attributes[0]->newInstance();
        $this->assertSame('Test Query', $instance->label);
    }

    public function test_non_attributed_method_has_no_attribute(): void
    {
        $reflection = new \ReflectionMethod(DiagnoseQueryTestFixture::class, 'normalMethod');
        $attributes = $reflection->getAttributes(DiagnoseQuery::class);

        $this->assertCount(0, $attributes);
    }

    public function test_attribute_targets_methods_only(): void
    {
        $reflection = new \ReflectionClass(DiagnoseQuery::class);
        $attributes = $reflection->getAttributes(\Attribute::class);

        $this->assertCount(1, $attributes);

        $attr = $attributes[0]->newInstance();
        $this->assertSame(\Attribute::TARGET_METHOD, $attr->flags);
    }

    public function test_attribute_with_description_only(): void
    {
        $reflection = new \ReflectionMethod(DiagnoseQueryTestFixture::class, 'descriptionOnly');
        $attributes = $reflection->getAttributes(DiagnoseQuery::class);

        $this->assertCount(1, $attributes);

        $instance = $attributes[0]->newInstance();
        $this->assertSame('', $instance->label);
        $this->assertSame('Gets recent orders', $instance->description);
    }
}

/**
 * @internal Test fixture — not part of the public API.
 */
class DiagnoseQueryTestFixture
{
    #[DiagnoseQuery(label: 'Test Query')]
    public function annotatedMethod(): void {}

    public function normalMethod(): void {}

    #[DiagnoseQuery(description: 'Gets recent orders')]
    public function descriptionOnly(): void {}
}
