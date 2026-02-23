<?php

declare(strict_types=1);

namespace QuerySentinel\Enums;

enum Severity: string
{
    case Info = 'info';
    case Optimization = 'optimization';
    case Warning = 'warning';
    case Critical = 'critical';

    public function weight(): int
    {
        return match ($this) {
            self::Info => 0,
            self::Optimization => 1,
            self::Warning => 5,
            self::Critical => 20,
        };
    }

    public function isFailing(): bool
    {
        return $this === self::Critical;
    }

    public function consoleColor(): string
    {
        return match ($this) {
            self::Critical => 'red',
            self::Warning => 'yellow',
            self::Optimization => 'green',
            self::Info => 'gray',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Critical => '!!',
            self::Warning => '!',
            self::Optimization => '*',
            self::Info => 'i',
        };
    }

    public function priority(): int
    {
        return match ($this) {
            self::Critical => 1,
            self::Warning => 2,
            self::Optimization => 3,
            self::Info => 4,
        };
    }
}
