<?php

declare(strict_types=1);

namespace QuerySentinel\Console;

use Illuminate\Console\Command;
use QuerySentinel\Core\Engine;
use QuerySentinel\Exceptions\EngineAbortException;

final class DiagnoseQueryCommand extends Command
{
    /** @var string */
    protected $signature = 'query:diagnose
        {sql : The SQL query to analyze}
        {--json : Output report as JSON}
        {--connection= : Database connection to use}
        {--shallow : Skip deep analysis (environment, index cardinality, plan stability, etc.)}
        {--fail-on-warning : Exit with non-zero code if warnings found}';

    /** @var string */
    protected $description = 'Analyze a SQL query for performance issues with full diagnostic output';

    public function handle(Engine $engine): int
    {
        $sql = $this->argument('sql');
        $renderer = new ReportRenderer;

        $this->info('Running EXPLAIN ANALYZE...');

        if ($this->option('shallow')) {
            return $this->handleShallow($engine, $sql, $renderer);
        }

        return $this->handleDeep($engine, $sql, $renderer);
    }

    private function handleDeep(Engine $engine, string $sql, ReportRenderer $renderer): int
    {
        try {
            $connectionName = $this->option('connection');
            $diagnostic = $engine->diagnose($sql, $connectionName);
        } catch (EngineAbortException $e) {
            if ($this->option('json')) {
                $this->line($e->failureReport->toJson(JSON_PRETTY_PRINT));
            } else {
                $renderer->renderValidationFailure($this, $e->failureReport, $sql);
            }

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line($diagnostic->toJson(JSON_PRETTY_PRINT));
        } else {
            $renderer->render($this, $diagnostic);
        }

        if ($this->option('fail-on-warning') && ! $diagnostic->report->passed) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function handleShallow(Engine $engine, string $sql, ReportRenderer $renderer): int
    {
        try {
            $report = $engine->analyzeSql($sql);
        } catch (EngineAbortException $e) {
            if ($this->option('json')) {
                $this->line($e->failureReport->toJson(JSON_PRETTY_PRINT));
            } else {
                $renderer->renderValidationFailure($this, $e->failureReport, $sql);
            }

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line($report->toJson(JSON_PRETTY_PRINT));
        } else {
            $renderer->renderShallow($this, $report);
        }

        if ($this->option('fail-on-warning') && ! $report->passed) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
