<?php

declare(strict_types=1);

namespace QuerySentinel\Contracts;

interface RuleRegistryInterface
{
    /**
     * Register a rule instance.
     */
    public function register(RuleInterface $rule): void;

    /**
     * Get all registered rules.
     *
     * @return array<int, RuleInterface>
     */
    public function getRules(): array;

    /**
     * Check if a rule with the given key is registered.
     */
    public function has(string $key): bool;
}
