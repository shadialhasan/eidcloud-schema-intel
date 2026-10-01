<?php

declare(strict_types=1);

namespace EidCloud\SchemaIntel\Inference;

use EidCloud\SchemaIntel\Model\InferredRelation;
use EidCloud\SchemaIntel\Model\TableDefinition;

class RelationInferrer
{
    /**
     * @param array<string, TableDefinition> $tables
     * @return InferredRelation[]
     */
    public function inferRelations(array $tables): array
    {
        $relations = [];
        $existingFkPairs = [];

        // Track existing explicit foreign keys to avoid redundant inferences
        foreach ($tables as $tName => $table) {
            foreach ($table->foreignKeys as $fk) {
                foreach ($fk->columns as $idx => $sourceCol) {
                    $targetCol = $fk->referencedColumns[$idx] ?? 'id';
                    $key = strtolower("{$tName}.{$sourceCol}->{$fk->referencedTable}.{$targetCol}");
                    $existingFkPairs[$key] = true;
                }
            }
        }

        // Map candidate target tables by normalized names (singular & plural)
        $tableLookup = [];
        foreach ($tables as $tName => $table) {
            $lower = strtolower($tName);
            $tableLookup[$lower] = $tName;

            // Plural / singular forms
            $singular = $this->singularize($lower);
            if ($singular !== $lower) {
                $tableLookup[$singular] = $tName;
            }
        }

        foreach ($tables as $sourceTableName => $sourceTable) {
            foreach ($sourceTable->columns as $column) {
                // Don't infer relations from the table's own primary key to itself unless parent_id
                $colName = $column->name;
                $colLower = strtolower($colName);

                // Self-referential pattern (parent_id, manager_id, reply_to_id, supervisor_id)
                if (in_array($colLower, ['parent_id', 'manager_id', 'reply_to_id', 'supervisor_id', 'reports_to_id', 'root_id'], true)) {
                    $targetCol = $this->findPrimaryKeyColumn($sourceTable) ?? 'id';
                    $pairKey = strtolower("{$sourceTableName}.{$colName}->{$sourceTableName}.{$targetCol}");

                    if (!isset($existingFkPairs[$pairKey])) {
                        $relations[] = new InferredRelation(
                            sourceTable: $sourceTableName,
                            sourceColumn: $colName,
                            targetTable: $sourceTableName,
                            targetColumn: $targetCol,
                            confidence: 'HIGH',
                            reason: "Self-referencing hierarchy pattern detected ('{$colName}')",
                            cardinality: 'N:1'
                        );
                        $existingFkPairs[$pairKey] = true;
                    }
                    continue;
                }

                // Match <entity>_id pattern (e.g., user_id, customer_id, order_id, tenant_id)
                if (preg_match('/^([a-z0-9_]+)_id$/i', $colName, $matches)) {
                    $entity = strtolower($matches[1]);
                    $targetTableName = $this->resolveTargetTable($entity, $tableLookup);

                    if ($targetTableName !== null) {
                        $targetTable = $tables[$targetTableName];
                        $targetCol = $this->findPrimaryKeyColumn($targetTable) ?? 'id';
                        $pairKey = strtolower("{$sourceTableName}.{$colName}->{$targetTableName}.{$targetCol}");

                        if (!isset($existingFkPairs[$pairKey])) {
                            $isSelf = strtolower($sourceTableName) === strtolower($targetTableName);
                            $confidence = $isSelf ? 'MEDIUM' : 'HIGH';
                            $cardinality = $this->isUniqueColumn($sourceTable, $colName) ? '1:1' : 'N:1';

                            $relations[] = new InferredRelation(
                                sourceTable: $sourceTableName,
                                sourceColumn: $colName,
                                targetTable: $targetTableName,
                                targetColumn: $targetCol,
                                confidence: $confidence,
                                reason: "Entity ID naming convention ('{$colName}' matches '{$targetTableName}.{$targetCol}')",
                                cardinality: $cardinality
                            );
                            $existingFkPairs[$pairKey] = true;
                        }
                        continue;
                    }
                }

                // Match audit columns: created_by, updated_by, deleted_by, author_id, owner_id -> users / accounts
                if (in_array($colLower, ['created_by', 'updated_by', 'deleted_by', 'author_id', 'owner_id', 'approved_by', 'assigned_to'], true)) {
                    $userTarget = $this->resolveUserTable($tableLookup);
                    if ($userTarget !== null) {
                        $targetTable = $tables[$userTarget];
                        $targetCol = $this->findPrimaryKeyColumn($targetTable) ?? 'id';
                        $pairKey = strtolower("{$sourceTableName}.{$colName}->{$userTarget}.{$targetCol}");

                        if (!isset($existingFkPairs[$pairKey])) {
                            $relations[] = new InferredRelation(
                                sourceTable: $sourceTableName,
                                sourceColumn: $colName,
                                targetTable: $userTarget,
                                targetColumn: $targetCol,
                                confidence: 'HIGH',
                                reason: "Audit/Ownership convention ('{$colName}' maps to user entity table '{$userTarget}')",
                                cardinality: 'N:1'
                            );
                            $existingFkPairs[$pairKey] = true;
                        }
                        continue;
                    }
                }

                // Match business codes / numbers: invoice_no, order_no, sku, tracking_number
                if (preg_match('/^([a-z0-9_]+)_(no|number|code|ref)$/i', $colName, $matches)) {
                    $prefix = strtolower($matches[1]);
                    $targetTableName = $this->resolveTargetTable($prefix, $tableLookup);

                    if ($targetTableName !== null && strtolower($targetTableName) !== strtolower($sourceTableName)) {
                        $targetTable = $tables[$targetTableName];
                        $targetCol = $this->findMatchingColumn($targetTable, [$colName, $matches[2], 'code', 'number', 'reference', 'id']);

                        if ($targetCol !== null) {
                            $pairKey = strtolower("{$sourceTableName}.{$colName}->{$targetTableName}.{$targetCol}");
                            if (!isset($existingFkPairs[$pairKey])) {
                                $relations[] = new InferredRelation(
                                    sourceTable: $sourceTableName,
                                    sourceColumn: $colName,
                                    targetTable: $targetTableName,
                                    targetColumn: $targetCol,
                                    confidence: 'MEDIUM',
                                    reason: "Business identifier pattern ('{$colName}' matches '{$targetTableName}.{$targetCol}')",
                                    cardinality: 'N:1'
                                );
                                $existingFkPairs[$pairKey] = true;
                            }
                        }
                    }
                }
            }
        }

        return $relations;
    }

    private function resolveTargetTable(string $entity, array $tableLookup): ?string
    {
        // 1. Direct match
        if (isset($tableLookup[$entity])) {
            return $tableLookup[$entity];
        }

        // 2. Pluralized match (user -> users, category -> categories, box -> boxes)
        $plural = $this->pluralize($entity);
        if (isset($tableLookup[$plural])) {
            return $tableLookup[$plural];
        }

        // 3. Singular match (order_items -> order_item, etc.)
        $singular = $this->singularize($entity);
        if (isset($tableLookup[$singular])) {
            return $tableLookup[$singular];
        }

        // 4. Underscore prefix stripping (e.g. billing_address_id -> addresses)
        if (str_contains($entity, '_')) {
            $parts = explode('_', $entity);
            $suffix = end($parts);
            if (isset($tableLookup[$suffix])) {
                return $tableLookup[$suffix];
            }
            $suffixPlural = $this->pluralize($suffix);
            if (isset($tableLookup[$suffixPlural])) {
                return $tableLookup[$suffixPlural];
            }
        }

        return null;
    }

    private function resolveUserTable(array $tableLookup): ?string
    {
        $candidates = ['users', 'user', 'accounts', 'account', 'members', 'member', 'profiles', 'profile'];
        foreach ($candidates as $cand) {
            if (isset($tableLookup[$cand])) {
                return $tableLookup[$cand];
            }
        }
        return null;
    }

    private function findPrimaryKeyColumn(TableDefinition $table): ?string
    {
        $pks = $table->getPrimaryKeyColumns();
        if (!empty($pks)) {
            return $pks[0];
        }
        if (isset($table->columns['id'])) {
            return 'id';
        }
        if (isset($table->columns[strtolower($table->name) . '_id'])) {
            return strtolower($table->name) . '_id';
        }
        return null;
    }

    private function findMatchingColumn(TableDefinition $table, array $candidateNames): ?string
    {
        foreach ($candidateNames as $cand) {
            foreach ($table->columns as $colName => $col) {
                if (strtolower($colName) === strtolower($cand)) {
                    return $colName;
                }
            }
        }
        return null;
    }

    private function isUniqueColumn(TableDefinition $table, string $column): bool
    {
        foreach ($table->indexes as $index) {
            if ($index->isUnique && count($index->columns) === 1 && strtolower($index->columns[0]) === strtolower($column)) {
                return true;
            }
        }
        return false;
    }

    private function singularize(string $word): string
    {
        if (str_ends_with($word, 'ies')) {
            return substr($word, 0, -3) . 'y';
        }
        if (str_ends_with($word, 'ses') || str_ends_with($word, 'xes') || str_ends_with($word, 'shes') || str_ends_with($word, 'ches')) {
            return substr($word, 0, -2);
        }
        if (str_ends_with($word, 's') && !str_ends_with($word, 'ss')) {
            return substr($word, 0, -1);
        }
        return $word;
    }

    private function pluralize(string $word): string
    {
        if (str_ends_with($word, 'y') && !preg_match('/[aeiou]y$/i', $word)) {
            return substr($word, 0, -1) . 'ies';
        }
        if (str_ends_with($word, 's') || str_ends_with($word, 'x') || str_ends_with($word, 'sh') || str_ends_with($word, 'ch')) {
            return $word . 'es';
        }
        return $word . 's';
    }
}
