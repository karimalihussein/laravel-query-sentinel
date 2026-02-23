<?php

declare(strict_types=1);

namespace QuerySentinel\Analyzers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use QuerySentinel\Enums\Severity;
use QuerySentinel\Support\Finding;
use QuerySentinel\Support\SqlParser;

/**
 * Section 3: Index & Cardinality Analysis.
 *
 * Evaluates index quality for all tables in the query: composite index
 * composition, column ordering (leftmost prefix), selectivity per column,
 * and usage detection.
 */
final class IndexCardinalityAnalyzer
{
    /**
     * @param  array<string, mixed>  $metrics  From MetricsExtractor
     * @param  array<int, array<string, mixed>>  $explainRows  From EXPLAIN tabular output
     * @return array{analysis: array<string, mixed>, findings: Finding[]}
     */
    public function analyze(string $rawSql, array $metrics, array $explainRows, ?string $connectionName = null): array
    {
        $findings = [];
        $analysis = [];

        foreach ($explainRows as $row) {
            $table = $row['table'] ?? null;
            $keyUsed = $row['key'] ?? null;
            $tableRows = (int) ($row['rows'] ?? 0);

            if ($table === null || str_starts_with($table, '<')) {
                continue;
            }

            $indexes = $this->getCachedIndexInfo($table, $connectionName);

            foreach ($indexes as $idx) {
                $indexName = $idx->Key_name;
                $cardinality = (int) ($idx->Cardinality ?? 0);
                $seqInIndex = (int) ($idx->Seq_in_index ?? 1);
                $columnName = $idx->Column_name ?? '';
                $nonUnique = (int) ($idx->Non_unique ?? 1);

                $selectivity = $tableRows > 0 ? round($cardinality / $tableRows, 4) : 0.0;

                $analysis[$table][$indexName]['columns'][$seqInIndex] = [
                    'column' => $columnName,
                    'cardinality' => $cardinality,
                    'selectivity' => $selectivity,
                ];
                $analysis[$table][$indexName]['is_unique'] = $nonUnique === 0;
                $analysis[$table][$indexName]['is_used'] = $keyUsed === $indexName;
                $analysis[$table][$indexName]['table_rows'] = $tableRows;
            }
        }

        $whereColumns = SqlParser::extractWhereColumns($rawSql);
        $joinColumns = SqlParser::extractJoinColumns($rawSql);
        $allFilterColumns = array_merge($whereColumns, $joinColumns);

        foreach ($analysis as $table => $indexes) {
            foreach ($indexes as $indexName => $indexData) {
                if (! $indexData['is_used']) {
                    continue;
                }

                $columns = $indexData['columns'];
                ksort($columns);
                $firstCol = reset($columns);

                if ($firstCol !== false && ! $this->isColumnInWhere($table, $firstCol['column'], $allFilterColumns)) {
                    $findings[] = new Finding(
                        severity: Severity::Warning,
                        category: 'index_cardinality',
                        title: sprintf('Leftmost prefix violation on %s.%s', $table, $indexName),
                        description: sprintf(
                            'Index `%s` has leftmost column `%s` but this column is not in the WHERE clause. MySQL may not use this index optimally.',
                            $indexName,
                            $firstCol['column']
                        ),
                        recommendation: sprintf('Reorder index columns or add `%s` to the WHERE clause.', $firstCol['column']),
                        metadata: ['table' => $table, 'index' => $indexName, 'leftmost_column' => $firstCol['column']],
                    );
                }

                $tableRows = $indexData['table_rows'];
                if ($firstCol !== false && $firstCol['selectivity'] > 0 && $firstCol['selectivity'] < 0.01 && $tableRows > 1000) {
                    $findings[] = new Finding(
                        severity: Severity::Optimization,
                        category: 'index_cardinality',
                        title: sprintf('Low selectivity on %s.%s: %.2f%%', $table, $indexName, $firstCol['selectivity'] * 100),
                        description: sprintf(
                            'The leading column `%s` of index `%s` has selectivity %.2f%% (cardinality %s / %s rows). Low selectivity means the index is not very discriminating.',
                            $firstCol['column'],
                            $indexName,
                            $firstCol['selectivity'] * 100,
                            number_format($firstCol['cardinality']),
                            number_format($tableRows)
                        ),
                        metadata: ['table' => $table, 'index' => $indexName, 'selectivity' => $firstCol['selectivity']],
                    );
                }
            }
        }

        return ['analysis' => $analysis, 'findings' => $findings];
    }

    /**
     * @return array<int, object>
     */
    private function getCachedIndexInfo(string $table, ?string $connectionName = null): array
    {
        $connection = DB::connection($connectionName);
        $dbName = $connection->getDatabaseName();
        $cacheKey = "query_sentinel_show_index_{$table}_{$dbName}";

        return Cache::remember($cacheKey, 300, function () use ($connection, $table) {
            try {
                return $connection->select("SHOW INDEX FROM `{$table}`");
            } catch (\Exception) {
                return [];
            }
        });
    }

    /**
     * @param  string[]  $whereColumns
     */
    private function isColumnInWhere(string $table, string $column, array $whereColumns): bool
    {
        foreach ($whereColumns as $whereCol) {
            $colParts = explode('.', $whereCol);
            $colName = count($colParts) === 2 ? $colParts[1] : $colParts[0];
            $tblName = count($colParts) === 2 ? $colParts[0] : null;

            if (strcasecmp($colName, $column) === 0) {
                if ($tblName === null || strcasecmp($tblName, $table) === 0) {
                    return true;
                }
            }
        }

        return false;
    }
}
