<?php

declare(strict_types=1);

namespace QuerySentinel\Analyzers;

use QuerySentinel\Enums\Severity;
use QuerySentinel\Support\Finding;

/**
 * Section 4: Join Analysis.
 *
 * Analyzes join strategy (nested loop, hash join, BNL), fanout multipliers,
 * explosion risk, and lookup efficiency (covering vs full row fetch).
 */
final class JoinAnalyzer
{
    /**
     * @param  string  $plan  EXPLAIN ANALYZE output
     * @param  array<string, mixed>  $metrics  From MetricsExtractor
     * @param  array<int, array<string, mixed>>  $explainRows  From EXPLAIN tabular output
     * @return array{join_analysis: array<string, mixed>, findings: Finding[]}
     */
    public function analyze(string $plan, array $metrics, array $explainRows): array
    {
        $findings = [];
        $joinAnalysis = [];

        $joinTypes = $this->detectJoinTypes($plan);
        $joinAnalysis['join_types'] = $joinTypes;

        $fanoutMultiplier = $this->calculateFanoutMultiplier($plan);
        $joinAnalysis['fanout_multiplier'] = $fanoutMultiplier;

        if (in_array('hash_join', $joinTypes, true)) {
            $findings[] = new Finding(
                severity: Severity::Info,
                category: 'join_analysis',
                title: 'Hash join detected',
                description: 'MySQL is using a hash join strategy. This is efficient for large unindexed joins but uses memory proportional to the build table size.',
            );
        }

        if (in_array('block_nested_loop', $joinTypes, true)) {
            $findings[] = new Finding(
                severity: Severity::Warning,
                category: 'join_analysis',
                title: 'Block Nested Loop (BNL) detected',
                description: 'MySQL is buffering rows for a join without a suitable index. This indicates a missing join index.',
                recommendation: 'Add an index on the join column of the inner table.',
            );
        }

        if ($fanoutMultiplier > 10.0) {
            $severity = $fanoutMultiplier > 100.0 ? Severity::Critical : Severity::Warning;
            $findings[] = new Finding(
                severity: $severity,
                category: 'join_analysis',
                title: sprintf('Join fanout risk: %.1fx multiplier', $fanoutMultiplier),
                description: sprintf(
                    'The join produces a %.1fx row multiplier. Each outer row generates %.1f inner rows on average, causing amplified work.',
                    $fanoutMultiplier,
                    $fanoutMultiplier
                ),
                recommendation: 'Add more selective join conditions, pre-filter with subqueries, or denormalize.',
                metadata: ['fanout_multiplier' => $fanoutMultiplier],
            );
        }

        $lookupEfficiency = [];
        foreach ($explainRows as $row) {
            $table = $row['table'] ?? null;
            $extra = $row['Extra'] ?? '';
            if ($table && ! str_starts_with($table, '<')) {
                $isCovering = str_contains($extra, 'Using index') && ! str_contains($extra, 'Using index condition');
                $lookupEfficiency[$table] = $isCovering ? 'covering' : 'full_row_fetch';
            }
        }
        $joinAnalysis['lookup_efficiency'] = $lookupEfficiency;

        $joinAnalysis['effective_fanout'] = $this->calculateEffectiveFanout($plan);
        $joinAnalysis['explosion_factor'] = $this->calculateExplosionFactor($joinAnalysis['effective_fanout'], $metrics);
        $joinAnalysis['multiplicative_risk'] = $this->classifyMultiplicativeRisk($joinAnalysis['explosion_factor']);
        $joinAnalysis['per_step'] = $this->extractPerStepFanout($plan, $metrics);

        if (in_array($joinAnalysis['multiplicative_risk'], ['multiplicative_risk', 'exponential_explosion'], true)) {
            $severity = $joinAnalysis['multiplicative_risk'] === 'exponential_explosion' ? Severity::Critical : Severity::Warning;
            $findings[] = new Finding(
                severity: $severity,
                category: 'join_analysis',
                title: sprintf('Multiplicative join risk: %s (explosion factor: %.1fx)', $joinAnalysis['multiplicative_risk'], $joinAnalysis['explosion_factor']),
                description: sprintf(
                    'The join produces an effective fanout of %.0f rows with an explosion factor of %.1fx relative to the driving table. This indicates potentially unbounded row multiplication.',
                    $joinAnalysis['effective_fanout'],
                    $joinAnalysis['explosion_factor']
                ),
                recommendation: 'Add more selective join conditions, add indexes on inner join tables, or pre-filter with subqueries.',
                metadata: [
                    'effective_fanout' => $joinAnalysis['effective_fanout'],
                    'explosion_factor' => $joinAnalysis['explosion_factor'],
                    'risk' => $joinAnalysis['multiplicative_risk'],
                ],
            );
        }

        return ['join_analysis' => $joinAnalysis, 'findings' => $findings];
    }

    /**
     * @return string[] e.g., ['nested_loop', 'hash_join', 'block_nested_loop']
     */
    private function detectJoinTypes(string $plan): array
    {
        $types = [];

        if (preg_match('/Nested loop/i', $plan)) {
            $types[] = 'nested_loop';
        }
        if (preg_match('/Hash join/i', $plan)) {
            $types[] = 'hash_join';
        }
        if (preg_match('/Block Nested Loop|BNL/i', $plan)) {
            $types[] = 'block_nested_loop';
        }
        if (preg_match('/Batched Key Access|BKA/i', $plan)) {
            $types[] = 'batched_key_access';
        }

        if (empty($types)) {
            $types[] = 'simple';
        }

        return $types;
    }

    /**
     * Calculate the effective fanout as the product of all per-step fanouts.
     *
     * Parses the plan for `(actual time=... rows=N loops=M)` patterns,
     * computes `rows * loops` per step, and returns the product.
     */
    private function calculateEffectiveFanout(string $plan): float
    {
        $pattern = '/\(actual\s+time=[\d.]+\.\.[\d.]+\s+rows=(\d+)\s+loops=(\d+)\)/i';

        if (! preg_match_all($pattern, $plan, $matches, PREG_SET_ORDER)) {
            return 1.0;
        }

        $product = 1.0;
        foreach ($matches as $match) {
            $rows = (int) $match[1];
            $loops = (int) $match[2];
            $stepFanout = (float) ($rows * $loops);
            if ($stepFanout > 0) {
                $product *= $stepFanout;
            }
        }

        return $product;
    }

    /**
     * Calculate the explosion factor: effective_fanout / driving_table_rows.
     *
     * The driving table is the first entry in `per_table_estimates`,
     * or falls back to `rows_returned`.
     *
     * @param  array<string, mixed>  $metrics
     */
    private function calculateExplosionFactor(float $effectiveFanout, array $metrics): float
    {
        $drivingRows = 1;

        $perTableEstimates = $metrics['per_table_estimates'] ?? [];
        if (is_array($perTableEstimates) && ! empty($perTableEstimates)) {
            $first = reset($perTableEstimates);
            if (is_array($first)) {
                $drivingRows = ($first['actual_rows'] ?? 1) * ($first['loops'] ?? 1);
            }
        }

        if ($drivingRows < 1) {
            $drivingRows = max((int) ($metrics['rows_returned'] ?? 1), 1);
        }

        return $effectiveFanout / (float) max($drivingRows, 1);
    }

    /**
     * Classify multiplicative risk based on the explosion factor.
     */
    private function classifyMultiplicativeRisk(float $explosionFactor): string
    {
        if ($explosionFactor > 1000) {
            return 'exponential_explosion';
        }
        if ($explosionFactor > 100) {
            return 'multiplicative_risk';
        }
        if ($explosionFactor > 10) {
            return 'linear_amplification';
        }

        return 'contained';
    }

    /**
     * Extract per-step fanout data from the plan.
     *
     * Parses per-table step data and returns an array of entries with
     * table name, step fanout, rows, and loops.
     *
     * @param  array<string, mixed>  $metrics
     * @return array<int, array{table: string, step_fanout: float, rows: int, loops: int}>
     */
    private function extractPerStepFanout(string $plan, array $metrics): array
    {
        $pattern = '/(?:scan|lookup|search)\s+on\s+(\w+).*?\(actual\s+time=[\d.]+\.\.[\d.]+\s+rows=(\d+)\s+loops=(\d+)\)/is';

        $steps = [];
        if (preg_match_all($pattern, $plan, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $rows = (int) $match[2];
                $loops = (int) $match[3];
                $steps[] = [
                    'table' => $match[1],
                    'step_fanout' => (float) ($rows * $loops),
                    'rows' => $rows,
                    'loops' => $loops,
                ];
            }
        }

        return $steps;
    }

    /**
     * Calculate join fanout multiplier from the EXPLAIN ANALYZE plan.
     */
    private function calculateFanoutMultiplier(string $plan): float
    {
        $pattern = '/(?:scan|lookup|search)\s+on\s+(\w+).*?\(actual\s+time=[\d.]+\.\.[\d.]+\s+rows=(\d+)\s+loops=(\d+)\)/is';

        if (! preg_match_all($pattern, $plan, $matches, PREG_SET_ORDER)) {
            return 1.0;
        }

        $tableRows = [];
        foreach ($matches as $match) {
            $table = $match[1];
            $rows = (int) $match[2];
            $loops = (int) $match[3];
            $totalRows = $rows * $loops;
            $tableRows[$table] = max($tableRows[$table] ?? 0, $totalRows);
        }

        if (count($tableRows) < 2) {
            return 1.0;
        }

        $values = array_values($tableRows);
        sort($values);
        $smallest = max($values[0], 1);
        $largest = end($values);

        return round($largest / $smallest, 2);
    }
}
