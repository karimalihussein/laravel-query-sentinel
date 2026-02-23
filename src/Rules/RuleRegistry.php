<?php

declare(strict_types=1);

namespace QuerySentinel\Rules;

use QuerySentinel\Contracts\RuleInterface;
use QuerySentinel\Contracts\RuleRegistryInterface;

final class RuleRegistry implements RuleRegistryInterface
{
    /** @var array<string, RuleInterface> */
    private array $rules = [];

    public function register(RuleInterface $rule): void
    {
        $this->rules[$rule->key()] = $rule;
    }

    public function getRules(): array
    {
        return array_values($this->rules);
    }

    public function has(string $key): bool
    {
        return isset($this->rules[$key]);
    }
}
