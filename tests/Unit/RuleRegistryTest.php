<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QuerySentinel\Contracts\RuleInterface;
use QuerySentinel\Rules\RuleRegistry;

final class RuleRegistryTest extends TestCase
{
    public function test_register_and_retrieve_rules(): void
    {
        $registry = new RuleRegistry;

        $rule = $this->createMock(RuleInterface::class);
        $rule->method('key')->willReturn('test_rule');
        $rule->method('name')->willReturn('Test Rule');

        $registry->register($rule);

        $this->assertCount(1, $registry->getRules());
        $this->assertTrue($registry->has('test_rule'));
        $this->assertFalse($registry->has('nonexistent'));
    }

    public function test_duplicate_key_replaces_rule(): void
    {
        $registry = new RuleRegistry;

        $rule1 = $this->createMock(RuleInterface::class);
        $rule1->method('key')->willReturn('same_key');

        $rule2 = $this->createMock(RuleInterface::class);
        $rule2->method('key')->willReturn('same_key');

        $registry->register($rule1);
        $registry->register($rule2);

        $this->assertCount(1, $registry->getRules());
        $this->assertSame($rule2, $registry->getRules()[0]);
    }

    public function test_empty_registry_returns_empty_array(): void
    {
        $registry = new RuleRegistry;

        $this->assertSame([], $registry->getRules());
        $this->assertFalse($registry->has('anything'));
    }
}
