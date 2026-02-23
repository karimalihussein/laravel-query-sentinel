<?php

declare(strict_types=1);

namespace QuerySentinel\Diagnostics;

use QuerySentinel\Analyzers\ScalabilityEstimator;
use QuerySentinel\Contracts\DriverInterface;
use QuerySentinel\Contracts\PlanParserInterface;
use QuerySentinel\Contracts\RuleRegistryInterface;
use QuerySentinel\Contracts\ScoringEngineInterface;
use QuerySentinel\Core\Engine;
use QuerySentinel\Core\QueryAnalyzer;
use QuerySentinel\Support\DiagnosticReport;
use QuerySentinel\Support\ExecutionGuard;
use QuerySentinel\Support\Report;
use QuerySentinel\Support\SqlSanitizer;

/**
 * Backward-compatible diagnostics entry point.
 *
 * Delegates to Core\QueryAnalyzer for all analysis logic.
 * Maintained for backward compatibility with existing code that
 * resolves or constructs QueryDiagnostics directly.
 *
 * @deprecated Use Core\Engine (via facade) or Core\QueryAnalyzer directly.
 */
final class QueryDiagnostics
{
    private readonly QueryAnalyzer $analyzer;

    public function __construct(
        private readonly DriverInterface $driver,
        private readonly PlanParserInterface $parser,
        private readonly ScoringEngineInterface $scoringEngine,
        private readonly RuleRegistryInterface $ruleRegistry,
        private readonly ScalabilityEstimator $scalabilityEstimator = new ScalabilityEstimator,
    ) {
        $this->analyzer = new QueryAnalyzer(
            $this->driver,
            $this->parser,
            $this->scoringEngine,
            $this->ruleRegistry,
            $this->scalabilityEstimator,
        );
    }

    /**
     * Analyze a SQL query and produce a complete diagnostic report.
     */
    public function analyze(string $sql): Report
    {
        return $this->analyzer->analyze($sql);
    }

    /**
     * Full diagnostic analysis with deep analyzers.
     */
    public function diagnose(string $sql, ?string $connectionName = null): DiagnosticReport
    {
        $engine = new Engine(
            $this->analyzer,
            new ExecutionGuard,
            new SqlSanitizer,
        );

        return $engine->diagnose($sql, $connectionName);
    }

    public function getDriver(): DriverInterface
    {
        return $this->driver;
    }

    public function getParser(): PlanParserInterface
    {
        return $this->parser;
    }

    public function getScoringEngine(): ScoringEngineInterface
    {
        return $this->scoringEngine;
    }

    public function getRuleRegistry(): RuleRegistryInterface
    {
        return $this->ruleRegistry;
    }

    /**
     * Access the underlying core analyzer.
     */
    public function getCoreAnalyzer(): QueryAnalyzer
    {
        return $this->analyzer;
    }
}
