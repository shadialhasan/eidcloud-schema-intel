<?php

declare(strict_types=1);

namespace EidCloud\SchemaIntel\Inference;

use EidCloud\SchemaIntel\Drivers\DatabaseDriverInterface;
use EidCloud\SchemaIntel\Model\InferredRelation;
use EidCloud\SchemaIntel\Model\MissingIndexSuggestion;
use EidCloud\SchemaIntel\Model\TableDefinition;

class IndexAdvisor
{
    /**
     * Common columns that are frequently filtered, sorted, or joined on
     */
    private const COMMON_QUERY_COLUMNS = [
        'status' => 'Filter column frequently used in WHERE clauses',
        'is_active' => 'Boolean flag column frequently used in WHERE conditions',
        'type' => 'Category/type filter column',
        'email' => 'Lookup column frequently used in authentication/search',
        'slug' => 'Unique slug or identifier frequently looked up in URLs',
        'created_at' => 'Timestamp frequently used in ORDER BY or date range filters',
        'published_at' => 'Timestamp frequently used for date-based queries',
    ];

    /**
     * @param array<string, TableDefinition> $tables
     * @param InferredRelation[] $inferredRelations
     * @param DatabaseDriverInterface|null $driver
     * @return MissingIndexSuggestion[]
     */
    public function detectMissingIndexes(
        array $tables,
        array $inferredRelations = [],
        ?DatabaseDriverInterface $driver = null
    ): array {
        $suggestions = [];

        foreach ($tables as $tableName => $table) {
            // 1. Check explicit Foreign Keys
            foreach ($table->foreignKeys as $fk) {
                if (count($fk->columns) === 1) {
                    $col = $fk->columns[0];
                    if (!$table->hasIndexLeadingWith($col)) {
                        $idxName = $this->buildIndexName($tableName, [$col]);
                        $sql = $driver
                            ? $driver->generateCreateIndexSql($tableName, $idxName, [$col])
                            : "CREATE INDEX {$idxName} ON {$tableName} ({$col});";

                        $suggestions[] = new MissingIndexSuggestion(
                            table: $tableName,
                            columns: [$col],
                            reason: "Foreign key column '{$col}' referencing '{$fk->referencedTable}' has no index (causes full table scans on JOINs/cascades)",
                            severity: 'CRITICAL',
                            suggestedIndexName: $idxName,
                            suggestedSql: $sql
                        );
                    }
                } elseif (count($fk->columns) > 1) {
                    $firstCol = $fk->columns[0];
                    if (!$table->hasIndexLeadingWith($firstCol)) {
                        $idxName = $this->buildIndexName($tableName, $fk->columns);
                        $sql = $driver
                            ? $driver->generateCreateIndexSql($tableName, $idxName, $fk->columns)
                            : "CREATE INDEX {$idxName} ON {$tableName} (" . implode(', ', $fk->columns) . ");";

                        $suggestions[] = new MissingIndexSuggestion(
                            table: $tableName,
                            columns: $fk->columns,
                            reason: "Composite foreign key (" . implode(', ', $fk->columns) . ") referencing '{$fk->referencedTable}' has no index leading with '" . $firstCol . "'",
                            severity: 'CRITICAL',
                            suggestedIndexName: $idxName,
                            suggestedSql: $sql
                        );
                    }
                }
            }

            // 2. Check Inferred Relations for this table
            foreach ($inferredRelations as $relation) {
                if (strtolower($relation->sourceTable) === strtolower($tableName)) {
                    $col = $relation->sourceColumn;
                    if (!$table->hasIndexLeadingWith($col)) {
                        // Check if already suggested
                        if (!$this->isAlreadySuggested($suggestions, $tableName, [$col])) {
                            $idxName = $this->buildIndexName($tableName, [$col]);
                            $sql = $driver
                                ? $driver->generateCreateIndexSql($tableName, $idxName, [$col])
                                : "CREATE INDEX {$idxName} ON {$tableName} ({$col});";

                            $suggestions[] = new MissingIndexSuggestion(
                                table: $tableName,
                                columns: [$col],
                                reason: "Inferred relationship column '{$col}' referencing '{$relation->targetTable}.{$relation->targetColumn}' lacks an index",
                                severity: 'HIGH',
                                suggestedIndexName: $idxName,
                                suggestedSql: $sql
                            );
                        }
                    }
                }
            }

            // 3. Frequently queried columns (status, is_active, email, slug, created_at)
            foreach ($table->columns as $colName => $colDef) {
                $lowerCol = strtolower($colName);
                if (isset(self::COMMON_QUERY_COLUMNS[$lowerCol])) {
                    if (!$table->hasIndexLeadingWith($colName) && !$colDef->isPrimaryKey) {
                        if (!$this->isAlreadySuggested($suggestions, $tableName, [$colName])) {
                            $idxName = $this->buildIndexName($tableName, [$colName]);
                            $sql = $driver
                                ? $driver->generateCreateIndexSql($tableName, $idxName, [$colName])
                                : "CREATE INDEX {$idxName} ON {$tableName} ({$colName});";

                            $severity = in_array($lowerCol, ['email', 'slug', 'status']) ? 'HIGH' : 'MEDIUM';

                            $suggestions[] = new MissingIndexSuggestion(
                                table: $tableName,
                                columns: [$colName],
                                reason: self::COMMON_QUERY_COLUMNS[$lowerCol],
                                severity: $severity,
                                suggestedIndexName: $idxName,
                                suggestedSql: $sql
                            );
                        }
                    }
                }
            }
        }

        return $suggestions;
    }

    private function buildIndexName(string $tableName, array $columns): string
    {
        $cleanTable = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
        $cleanCols = implode('_', array_map(fn($c) => preg_replace('/[^a-zA-Z0-9_]/', '', $c), $columns));
        return "idx_{$cleanTable}_{$cleanCols}";
    }

    private function isAlreadySuggested(array $suggestions, string $table, array $columns): bool
    {
        foreach ($suggestions as $s) {
            if ($s->table === $table && $s->columns === $columns) {
                return true;
            }
        }
        return false;
    }
}
