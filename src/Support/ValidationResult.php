<?php

declare(strict_types=1);

namespace QuerySentinel\Support;

/**
 * Result of validation pipeline or single validator.
 * No exceptions: callers decide whether to abort or continue.
 */
final readonly class ValidationResult
{
    /**
     * @param  ValidationFailureReport[]  $failures
     */
    private function __construct(
        private bool $valid,
        private array $failures,
    ) {}

    public static function valid(): self
    {
        return new self(true, []);
    }

    /**
     * @param  ValidationFailureReport[]  $failures
     */
    public static function invalid(array $failures): self
    {
        return new self(false, $failures);
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    /**
     * @return ValidationFailureReport[]
     */
    public function getFailures(): array
    {
        return $this->failures;
    }

    public function getFirstFailure(): ?ValidationFailureReport
    {
        return $this->failures[0] ?? null;
    }

    /**
     * Merge with another result. Valid only if both are valid.
     */
    public function merge(self $other): self
    {
        if ($this->valid && $other->valid) {
            return self::valid();
        }

        $merged = array_merge($this->failures, $other->failures);

        return self::invalid($merged);
    }
}
