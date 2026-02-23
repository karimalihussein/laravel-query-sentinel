<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QuerySentinel\Support\ThresholdGuard;

final class ThresholdGuardTest extends TestCase
{
    public function test_always_logs_when_threshold_zero(): void
    {
        $this->assertTrue(ThresholdGuard::shouldLog(0.0));
        $this->assertTrue(ThresholdGuard::shouldLog(1.0));
        $this->assertTrue(ThresholdGuard::shouldLog(0.001));
    }

    public function test_logs_when_above_method_threshold(): void
    {
        $this->assertTrue(ThresholdGuard::shouldLog(150.0, 100));
    }

    public function test_does_not_log_when_below_method_threshold(): void
    {
        $this->assertFalse(ThresholdGuard::shouldLog(50.0, 100));
    }

    public function test_logs_when_above_global_threshold(): void
    {
        $this->assertTrue(ThresholdGuard::shouldLog(150.0, 0, 100));
    }

    public function test_does_not_log_when_below_global_threshold(): void
    {
        $this->assertFalse(ThresholdGuard::shouldLog(50.0, 0, 100));
    }

    public function test_effective_threshold_is_maximum(): void
    {
        // method=50, global=100 → effective=100
        $this->assertFalse(ThresholdGuard::shouldLog(75.0, 50, 100));

        // method=100, global=50 → effective=100
        $this->assertFalse(ThresholdGuard::shouldLog(75.0, 100, 50));
    }

    public function test_logs_at_exact_threshold(): void
    {
        $this->assertTrue(ThresholdGuard::shouldLog(100.0, 100));
    }

    public function test_defaults_to_always_log(): void
    {
        // No thresholds → always log
        $this->assertTrue(ThresholdGuard::shouldLog(0.0, 0, 0));
    }

    public function test_negative_threshold_treated_as_always_log(): void
    {
        $this->assertTrue(ThresholdGuard::shouldLog(0.0, -1, 0));
    }
}
