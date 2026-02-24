<?php

declare(strict_types=1);

namespace QuerySentinel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QuerySentinel\Rules\DeepNestedLoopRule;
use QuerySentinel\Rules\FullTableScanRule;
use QuerySentinel\Rules\IndexMergeRule;
use QuerySentinel\Rules\LimitIneffectiveRule;
use QuerySentinel\Rules\NoIndexRule;
use QuerySentinel\Rules\QuadraticComplexityRule;
use QuerySentinel\Rules\StaleStatsRule;
use QuerySentinel\Rules\TempTableRule;
use QuerySentinel\Rules\WeedoutRule;

final class RulesTest extends TestCase
{
    // FullTableScanRule

    public function test_full_table_scan_rule_triggers(): void
    {
        $rule = new FullTableScanRule;
        $finding = $rule->evaluate(['has_table_scan' => true, 'rows_examined' => 50_000]);

        $this->assertNotNull($finding);
        $this->assertSame('critical', $finding['severity']);
        $this->assertSame('full_table_scan', $finding['category']);
    }

    public function test_full_table_scan_rule_does_not_trigger(): void
    {
        $rule = new FullTableScanRule;
        $finding = $rule->evaluate(['has_table_scan' => false]);

        $this->assertNull($finding);
    }

    public function test_full_table_scan_warning_for_small_scan(): void
    {
        $rule = new FullTableScanRule;
        $finding = $rule->evaluate(['has_table_scan' => true, 'rows_examined' => 500]);

        $this->assertNotNull($finding);
        $this->assertSame('warning', $finding['severity']);
    }

    // TempTableRule

    public function test_temp_table_rule_triggers(): void
    {
        $rule = new TempTableRule;
        $finding = $rule->evaluate(['has_temp_table' => true, 'has_disk_temp' => false]);

        $this->assertNotNull($finding);
        $this->assertSame('warning', $finding['severity']);
    }

    public function test_temp_table_disk_based_is_critical(): void
    {
        $rule = new TempTableRule;
        $finding = $rule->evaluate(['has_temp_table' => true, 'has_disk_temp' => true]);

        $this->assertNotNull($finding);
        $this->assertSame('critical', $finding['severity']);
    }

    public function test_temp_table_rule_does_not_trigger(): void
    {
        $rule = new TempTableRule;
        $this->assertNull($rule->evaluate(['has_temp_table' => false]));
    }

    // WeedoutRule

    public function test_weedout_rule_triggers(): void
    {
        $rule = new WeedoutRule;
        $finding = $rule->evaluate(['has_weedout' => true]);

        $this->assertNotNull($finding);
        $this->assertSame('warning', $finding['severity']);
        $this->assertSame('weedout', $finding['category']);
    }

    public function test_weedout_rule_does_not_trigger(): void
    {
        $rule = new WeedoutRule;
        $this->assertNull($rule->evaluate(['has_weedout' => false]));
    }

    // DeepNestedLoopRule

    public function test_deep_nested_loop_triggers_at_threshold(): void
    {
        $rule = new DeepNestedLoopRule(threshold: 4);
        $finding = $rule->evaluate(['nested_loop_depth' => 5]);

        $this->assertNotNull($finding);
        $this->assertSame('warning', $finding['severity']);
    }

    public function test_deep_nested_loop_critical_at_6(): void
    {
        $rule = new DeepNestedLoopRule(threshold: 4);
        $finding = $rule->evaluate(['nested_loop_depth' => 6]);

        $this->assertNotNull($finding);
        $this->assertSame('critical', $finding['severity']);
    }

    public function test_deep_nested_loop_does_not_trigger_below_threshold(): void
    {
        $rule = new DeepNestedLoopRule(threshold: 4);
        $this->assertNull($rule->evaluate(['nested_loop_depth' => 3]));
    }

    // IndexMergeRule

    public function test_index_merge_rule_triggers(): void
    {
        $rule = new IndexMergeRule;
        $finding = $rule->evaluate(['has_index_merge' => true, 'indexes_used' => ['idx_a', 'idx_b']]);

        $this->assertNotNull($finding);
        $this->assertSame('warning', $finding['severity']);
    }

    // StaleStatsRule

    public function test_stale_stats_rule_triggers_on_deviation(): void
    {
        $rule = new StaleStatsRule;
        $finding = $rule->evaluate([
            'per_table_estimates' => [
                'users' => ['estimated_rows' => 100, 'actual_rows' => 10_000, 'loops' => 1],
            ],
        ]);

        $this->assertNotNull($finding);
        $this->assertSame('warning', $finding['severity']);
        $this->assertStringContainsString('users', $finding['description']);
    }

    public function test_stale_stats_rule_does_not_trigger_on_accurate(): void
    {
        $rule = new StaleStatsRule;
        $finding = $rule->evaluate([
            'per_table_estimates' => [
                'users' => ['estimated_rows' => 100, 'actual_rows' => 95, 'loops' => 1],
            ],
        ]);

        $this->assertNull($finding);
    }

    // LimitIneffectiveRule

    public function test_limit_ineffective_triggers(): void
    {
        $rule = new LimitIneffectiveRule;
        $finding = $rule->evaluate([
            'has_early_termination' => false,
            'rows_examined' => 100_000,
            'rows_returned' => 50,
        ]);

        $this->assertNotNull($finding);
        $this->assertSame('warning', $finding['severity']);
    }

    public function test_limit_ineffective_does_not_trigger_with_early_termination(): void
    {
        $rule = new LimitIneffectiveRule;
        $finding = $rule->evaluate([
            'has_early_termination' => true,
            'rows_examined' => 100_000,
            'rows_returned' => 50,
        ]);

        $this->assertNull($finding);
    }

    // QuadraticComplexityRule

    public function test_quadratic_complexity_triggers(): void
    {
        $rule = new QuadraticComplexityRule;
        $finding = $rule->evaluate([
            'complexity' => 'O(n²)',
            'max_loops' => 50_000,
            'nested_loop_depth' => 5,
        ]);

        $this->assertNotNull($finding);
        $this->assertSame('critical', $finding['severity']);
    }

    public function test_quadratic_complexity_does_not_trigger_for_linear(): void
    {
        $rule = new QuadraticComplexityRule;
        $this->assertNull($rule->evaluate(['complexity' => 'O(n)']));
    }

    // NoIndexRule

    public function test_no_index_rule_triggers(): void
    {
        $rule = new NoIndexRule;
        $finding = $rule->evaluate([
            'is_index_backed' => false,
            'indexes_used' => [],
            'tables_accessed' => ['users'],
        ]);

        $this->assertNotNull($finding);
        $this->assertSame('critical', $finding['severity']);
    }

    public function test_no_index_rule_does_not_trigger_with_index(): void
    {
        $rule = new NoIndexRule;
        $this->assertNull($rule->evaluate([
            'is_index_backed' => true,
            'indexes_used' => ['PRIMARY'],
        ]));
    }

    public function test_no_index_rule_does_not_trigger_for_zero_row_const(): void
    {
        $rule = new NoIndexRule;
        $this->assertNull($rule->evaluate([
            'is_index_backed' => false,
            'indexes_used' => [],
            'is_zero_row_const' => true,
            'tables_accessed' => ['users'],
        ]));
    }

    public function test_no_index_rule_does_not_trigger_for_const_row(): void
    {
        $rule = new NoIndexRule;
        $this->assertNull($rule->evaluate([
            'is_index_backed' => false,
            'indexes_used' => [],
            'is_zero_row_const' => false,
            'primary_access_type' => 'const_row',
            'tables_accessed' => ['users'],
        ]));
    }

    public function test_no_index_rule_does_not_trigger_for_single_row_lookup(): void
    {
        $rule = new NoIndexRule;
        $this->assertNull($rule->evaluate([
            'is_index_backed' => false,
            'indexes_used' => [],
            'is_zero_row_const' => false,
            'primary_access_type' => 'single_row_lookup',
            'tables_accessed' => ['users'],
        ]));
    }

    // QuadraticComplexityRule — negative cases

    public function test_quadratic_complexity_does_not_trigger_for_constant(): void
    {
        $rule = new QuadraticComplexityRule;
        $this->assertNull($rule->evaluate(['complexity' => 'O(1)']));
    }

    public function test_quadratic_complexity_does_not_trigger_for_logarithmic(): void
    {
        $rule = new QuadraticComplexityRule;
        $this->assertNull($rule->evaluate(['complexity' => 'O(log n)']));
    }

    public function test_quadratic_complexity_does_not_trigger_for_log_range(): void
    {
        $rule = new QuadraticComplexityRule;
        $this->assertNull($rule->evaluate(['complexity' => 'O(log n + k)']));
    }

    public function test_quadratic_complexity_does_not_trigger_for_linearithmic(): void
    {
        $rule = new QuadraticComplexityRule;
        $this->assertNull($rule->evaluate(['complexity' => 'O(n log n)']));
    }
}
