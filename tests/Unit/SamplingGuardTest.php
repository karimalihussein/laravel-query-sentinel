<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QuerySentinel\Support\SamplingGuard;

final class SamplingGuardTest extends TestCase
{
    public function test_always_profiles_at_rate_one(): void
    {
        // Run 50 times to ensure it's always true
        for ($i = 0; $i < 50; $i++) {
            $this->assertTrue(SamplingGuard::shouldProfile(1.0, 1.0));
        }
    }

    public function test_never_profiles_at_rate_zero(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->assertFalse(SamplingGuard::shouldProfile(0.0, 1.0));
        }
    }

    public function test_never_profiles_when_global_rate_zero(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->assertFalse(SamplingGuard::shouldProfile(1.0, 0.0));
        }
    }

    public function test_effective_rate_is_minimum(): void
    {
        // method=0.0, global=1.0 → effective=0.0 → never
        $this->assertFalse(SamplingGuard::shouldProfile(0.0, 1.0));

        // method=1.0, global=0.0 → effective=0.0 → never
        $this->assertFalse(SamplingGuard::shouldProfile(1.0, 0.0));
    }

    public function test_default_global_rate_is_one(): void
    {
        // Only pass method rate, global defaults to 1.0
        $this->assertTrue(SamplingGuard::shouldProfile(1.0));
    }

    public function test_probabilistic_sampling_at_half_rate(): void
    {
        $sampled = 0;
        $runs = 1000;

        for ($i = 0; $i < $runs; $i++) {
            if (SamplingGuard::shouldProfile(0.5, 1.0)) {
                $sampled++;
            }
        }

        // At 50% rate, expect roughly 400-600 out of 1000 (generous range)
        $this->assertGreaterThan(300, $sampled);
        $this->assertLessThan(700, $sampled);
    }

    public function test_above_one_treated_as_always(): void
    {
        $this->assertTrue(SamplingGuard::shouldProfile(1.5, 1.0));
        $this->assertTrue(SamplingGuard::shouldProfile(1.0, 1.5));
    }
}
