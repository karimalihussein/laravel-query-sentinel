<?php

declare(strict_types=1);

namespace QuerySentinel\Analyzers;

use QuerySentinel\Contracts\DriverInterface;
use QuerySentinel\Enums\Severity;
use QuerySentinel\Support\Finding;

/**
 * Phase 10: Hypothetical Index Simulation.
 *
 * Tests index recommendations from the IndexSynthesisAnalyzer by simulating
 * what EXPLAIN would produce with proposed indexes. Creates temporary indexes,
 * captures before/after EXPLAIN snapshots, and compares access patterns.
 */
final class HypotheticalIndexAnalyzer
{
    private int $maxSimulations;

    private int $timeoutSeconds;

    /** @var string[] */
    private array $allowedEnvironments;

    /** @var callable(string): void */
    private $ddlExecutor;

    /**
     * @param  string[]  $allowedEnvironments
     * @param  callable(string): void|null  $ddlExecutor
     */
    public function __construct(
        int $maxSimulations = 3,
        int $timeoutSeconds = 5,
        array $allowedEnvironments = ['local', 'testing'],
        ?callable $ddlExecutor = null,
    ) {
        $this->maxSimulations = $maxSimulations;
        $this->timeoutSeconds = $timeoutSeconds;
        $this->allowedEnvironments = $allowedEnvironments;
        $this->ddlExecutor = $ddlExecutor ?? function (string $ddl): void {
            \Illuminate\Support\Facades\DB::statement($ddl);
        };
    }

    /**
     * @param  string  $sql  Raw SQL query
     * @param  array<string, mixed>|null  $indexSynthesis  From IndexSynthesisAnalyzer
     * @param  DriverInterface  $driver  For running EXPLAIN
     * @param  string  $currentEnvironment  Current app environment (e.g. 'local', 'production')
     * @return array{hypothetical_indexes: array<string, mixed>, findings: Finding[]}
     */
    public function analyze(
        string $sql,
        ?array $indexSynthesis,
        DriverInterface $driver,
        string $currentEnvironment = 'local',
    ): array {
        $findings = [];
        $simulations = [];

        // Guard: environment check
        if (! in_array($currentEnvironment, $this->allowedEnvironments, true)) {
            return [
                'hypothetical_indexes' => [
                    'enabled' => false,
                    'simulations' => [],
                    'best_recommendation' => null,
                ],
                'findings' => [],
            ];
        }

        // Guard: no recommendations
        $recommendations = $indexSynthesis['recommendations'] ?? [];
        if ($recommendations === []) {
            return [
                'hypothetical_indexes' => [
                    'enabled' => true,
                    'simulations' => [],
                    'best_recommendation' => null,
                ],
                'findings' => [],
            ];
        }

        // Limit to maxSimulations
        $recommendations = array_slice($recommendations, 0, $this->maxSimulations);

        $bestImprovement = 'none';
        $bestDdl = null;

        foreach ($recommendations as $recommendation) {
            $ddl = $recommendation['ddl'] ?? '';
            if ($ddl === '') {
                continue;
            }

            $simulation = $this->simulateIndex($sql, $ddl, $driver);
            $simulations[] = $simulation;

            if ($this->improvementRank($simulation['improvement']) < $this->improvementRank($bestImprovement)) {
                $bestImprovement = $simulation['improvement'];
                $bestDdl = $simulation['index_ddl'];
            }
        }

        // Generate findings
        foreach ($simulations as $simulation) {
            $finding = $this->generateFinding($simulation);
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return [
            'hypothetical_indexes' => [
                'enabled' => true,
                'simulations' => $simulations,
                'best_recommendation' => $bestDdl,
            ],
            'findings' => $findings,
        ];
    }

    /**
     * Simulate a single index creation, run before/after EXPLAIN, and compare.
     *
     * @return array{index_ddl: string, before: array{access_type: string, rows: int, key: string|null}, after: array{access_type: string, rows: int, key: string|null}, improvement: string, validated: bool, notes: string}
     */
    private function simulateIndex(string $sql, string $createDdl, DriverInterface $driver): array
    {
        $startTime = microtime(true);

        // Capture "before" EXPLAIN
        $beforeExplain = $driver->runExplain($sql);
        $before = $this->extractExplainSnapshot($beforeExplain);

        $dropDdl = $this->generateDropDdl($createDdl);
        $after = $before; // default to same as before if simulation fails
        $simulationError = null;

        try {
            // Create the index
            ($this->ddlExecutor)($createDdl);

            $elapsed = microtime(true) - $startTime;
            if ($elapsed > $this->timeoutSeconds) {
                $simulationError = sprintf('Simulation timed out after %.1fs (limit: %ds).', $elapsed, $this->timeoutSeconds);
            } else {
                try {
                    // Capture "after" EXPLAIN
                    $afterExplain = $driver->runExplain($sql);
                    $after = $this->extractExplainSnapshot($afterExplain);
                } catch (\Throwable $e) {
                    $simulationError = 'EXPLAIN after index creation failed: ' . $e->getMessage();
                }
            }
        } catch (\Throwable $e) {
            $simulationError = 'Index creation failed: ' . $e->getMessage();
        } finally {
            // Always attempt to drop the index
            if ($dropDdl !== null) {
                try {
                    ($this->ddlExecutor)($dropDdl);
                } catch (\Throwable) {
                    // Suppress drop errors — best effort cleanup
                }
            }
        }

        // Compare before/after
        $improvement = $this->classifyImprovement($before, $after);
        $validated = $this->accessTypeOrder($after['access_type']) < $this->accessTypeOrder($before['access_type']);

        $notes = $this->buildNotes($before, $after, $improvement, $simulationError);

        return [
            'index_ddl' => $createDdl,
            'before' => $before,
            'after' => $after,
            'improvement' => $improvement,
            'validated' => $validated,
            'notes' => $notes,
        ];
    }

    /**
     * Extract a snapshot from an EXPLAIN result row.
     *
     * @param  array<int, array<string, mixed>>  $explainResult
     * @return array{access_type: string, rows: int, key: string|null}
     */
    private function extractExplainSnapshot(array $explainResult): array
    {
        $row = $explainResult[0] ?? [];

        return [
            'access_type' => (string) ($row['type'] ?? $row['access_type'] ?? 'unknown'),
            'rows' => (int) ($row['rows'] ?? 0),
            'key' => isset($row['key']) ? (string) $row['key'] : null,
        ];
    }

    /**
     * Classify the improvement between before and after snapshots.
     *
     * @param  array{access_type: string, rows: int, key: string|null}  $before
     * @param  array{access_type: string, rows: int, key: string|null}  $after
     */
    private function classifyImprovement(array $before, array $after): string
    {
        $beforeOrder = $this->accessTypeOrder($before['access_type']);
        $afterOrder = $this->accessTypeOrder($after['access_type']);

        // If access type improved (lower order = better)
        if ($afterOrder < $beforeOrder) {
            return 'significant';
        }

        // Check row reduction
        $beforeRows = $before['rows'];
        $afterRows = $after['rows'];

        if ($beforeRows > 0) {
            $reduction = ($beforeRows - $afterRows) / $beforeRows;

            if ($reduction > 0.50) {
                return 'moderate';
            }

            if ($reduction > 0.10) {
                return 'marginal';
            }
        }

        return 'none';
    }

    /**
     * Rank access types from best (0) to worst.
     */
    private function accessTypeOrder(string $type): int
    {
        return match (strtolower($type)) {
            'system', 'const' => 0,
            'eq_ref' => 1,
            'ref', 'ref_or_null' => 2,
            'fulltext' => 3,
            'index_merge' => 4,
            'unique_subquery' => 5,
            'index_subquery' => 6,
            'range' => 7,
            'index' => 8,
            'all' => 9,
            default => 10,
        };
    }

    /**
     * Rank improvement levels for comparison (lower = better).
     */
    private function improvementRank(string $improvement): int
    {
        return match ($improvement) {
            'significant' => 0,
            'moderate' => 1,
            'marginal' => 2,
            default => 3,
        };
    }

    /**
     * Extract the index name from a CREATE INDEX DDL statement.
     */
    private function extractIndexName(string $ddl): ?string
    {
        if (preg_match('/CREATE\s+INDEX\s+`?([\w]+)`?/i', $ddl, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Extract the table name from a CREATE INDEX DDL statement.
     */
    private function extractTableName(string $ddl): ?string
    {
        if (preg_match('/\bON\s+`?([\w]+)`?/i', $ddl, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Generate a DROP INDEX DDL from a CREATE INDEX DDL.
     */
    private function generateDropDdl(string $createDdl): ?string
    {
        $indexName = $this->extractIndexName($createDdl);
        $tableName = $this->extractTableName($createDdl);

        if ($indexName === null || $tableName === null) {
            return null;
        }

        return sprintf('DROP INDEX `%s` ON `%s`', $indexName, $tableName);
    }

    /**
     * Build human-readable notes for the simulation result.
     *
     * @param  array{access_type: string, rows: int, key: string|null}  $before
     * @param  array{access_type: string, rows: int, key: string|null}  $after
     */
    private function buildNotes(array $before, array $after, string $improvement, ?string $error): string
    {
        if ($error !== null) {
            return $error;
        }

        $parts = [];

        if ($before['access_type'] !== $after['access_type']) {
            $parts[] = sprintf(
                'Access type changed from %s to %s.',
                $before['access_type'],
                $after['access_type']
            );
        }

        if ($before['rows'] > 0 && $after['rows'] !== $before['rows']) {
            $reduction = round(($before['rows'] - $after['rows']) / $before['rows'] * 100, 1);
            $parts[] = sprintf(
                'Rows examined changed from %d to %d (%.1f%% reduction).',
                $before['rows'],
                $after['rows'],
                $reduction
            );
        }

        if ($parts === []) {
            return 'No measurable improvement detected.';
        }

        return implode(' ', $parts);
    }

    /**
     * Generate a Finding for a simulation result, if warranted.
     *
     * @param  array{index_ddl: string, before: array{access_type: string, rows: int, key: string|null}, after: array{access_type: string, rows: int, key: string|null}, improvement: string, validated: bool, notes: string}  $simulation
     */
    private function generateFinding(array $simulation): ?Finding
    {
        if ($simulation['improvement'] === 'none') {
            return null;
        }

        $severity = match ($simulation['improvement']) {
            'significant' => Severity::Warning,
            'moderate' => Severity::Optimization,
            'marginal' => Severity::Info,
            default => Severity::Info,
        };

        $indexName = $this->extractIndexName($simulation['index_ddl']) ?? 'unknown';

        return new Finding(
            severity: $severity,
            category: 'hypothetical_index',
            title: sprintf('Hypothetical index `%s` shows %s improvement', $indexName, $simulation['improvement']),
            description: $simulation['notes'],
            recommendation: $simulation['validated'] ? $simulation['index_ddl'] : null,
            metadata: [
                'index_ddl' => $simulation['index_ddl'],
                'improvement' => $simulation['improvement'],
                'validated' => $simulation['validated'],
                'before_access_type' => $simulation['before']['access_type'],
                'after_access_type' => $simulation['after']['access_type'],
                'before_rows' => $simulation['before']['rows'],
                'after_rows' => $simulation['after']['rows'],
            ],
        );
    }
}
