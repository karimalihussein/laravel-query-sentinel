<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QuerySentinel\Attributes\QueryDiagnose;

final class QueryDiagnoseAttributeTest extends TestCase
{
    public function test_default_values(): void
    {
        $attribute = new QueryDiagnose;

        $this->assertSame(0, $attribute->thresholdMs);
        $this->assertSame(1.0, $attribute->sampleRate);
        $this->assertFalse($attribute->failOnCritical);
        $this->assertSame('performance', $attribute->logChannel);
    }

    public function test_custom_values(): void
    {
        $attribute = new QueryDiagnose(
            thresholdMs: 50,
            sampleRate: 0.10,
            failOnCritical: true,
            logChannel: 'slow-queries',
        );

        $this->assertSame(50, $attribute->thresholdMs);
        $this->assertSame(0.10, $attribute->sampleRate);
        $this->assertTrue($attribute->failOnCritical);
        $this->assertSame('slow-queries', $attribute->logChannel);
    }

    public function test_attribute_is_discoverable_via_reflection(): void
    {
        $reflection = new \ReflectionMethod(AttributeTestTarget::class, 'profiledMethod');
        $attributes = $reflection->getAttributes(QueryDiagnose::class);

        $this->assertCount(1, $attributes);

        $instance = $attributes[0]->newInstance();
        $this->assertSame(50, $instance->thresholdMs);
        $this->assertSame(0.5, $instance->sampleRate);
    }

    public function test_non_attributed_method_has_no_attribute(): void
    {
        $reflection = new \ReflectionMethod(AttributeTestTarget::class, 'normalMethod');
        $attributes = $reflection->getAttributes(QueryDiagnose::class);

        $this->assertCount(0, $attributes);
    }

    public function test_attribute_targets_methods_only(): void
    {
        $reflection = new \ReflectionClass(QueryDiagnose::class);
        $attributes = $reflection->getAttributes(\Attribute::class);

        $this->assertCount(1, $attributes);
        $attr = $attributes[0]->newInstance();
        $this->assertSame(\Attribute::TARGET_METHOD, $attr->flags);
    }
}

/**
 * Test target class for attribute discovery tests.
 */
class AttributeTestTarget
{
    #[QueryDiagnose(thresholdMs: 50, sampleRate: 0.5)]
    public function profiledMethod(): void {}

    public function normalMethod(): void {}
}
