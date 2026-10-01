<?php

declare(strict_types=1);

namespace EidCloud\SchemaIntel\Exporter;

use EidCloud\SchemaIntel\Model\SchemaIntelReport;

class MarkdownDataDictionaryExporter
{
    /**
     * Export data dictionary into rich Markdown format
     */
    public function export(SchemaIntelReport $report): string
    {
        $out = [];
        $out[] = "# Data Dictionary: `{$report->databaseName}`";
        $out[] = "";
        $out[] = "> Engine: **{$report->driverName}** | Generated: " . date('Y-m-d H:i:s');
        $out[] = "";

        // Summary table
        $out[] = "## Overview Summary";
        $out[] = "";
        $out[] = "| Metric | Count |";
        $out[] = "| :--- | :--- |";
        $out[] = "| **Total Tables** | " . count($report->tables) . " |";
        $out[] = "| **Total Columns** | " . array_sum(array_map(fn($t) => count($t->columns), $report->tables)) . " |";
        $out[] = "| **Explicit Foreign Keys** | " . array_sum(array_map(fn($t) => count($t->foreignKeys), $report->tables)) . " |";
        $out[] = "| **Inferred Relationships** | " . count($report->inferredRelations) . " |";
        $out[] = "| **Missing Index Advisories** | " . count($report->missingIndexes) . " |";
        $out[] = "";

        // Table index
        $out[] = "## Tables Directory";
        $out[] = "";
        foreach ($report->tables as $table) {
            $rowCount = $table->rowCount !== null ? number_format($table->rowCount) : 'N/A';
            $out[] = "- [{$table->name}](#" . strtolower($table->name) . ") - *{$rowCount} rows, " . count($table->columns) . " columns*";
        }
        $out[] = "";

        // Tables detail
        foreach ($report->tables as $table) {
            $out[] = "### Table: `{$table->name}`";
            if (!empty($table->comment)) {
                $out[] = "*{$table->comment}*";
                $out[] = "";
            }

            // Columns table
            $out[] = "#### Columns";
            $out[] = "| # | Name | Data Type | Nullable | Default | Key | Attributes |";
            $out[] = "| :- | :--- | :--- | :---: | :--- | :---: | :--- |";

            $pos = 1;
            foreach ($table->columns as $col) {
                $key = $col->isPrimaryKey ? '🔑 PK' : '';
                $attr = [];
                if ($col->isAutoIncrement) {
                    $attr[] = 'auto_increment';
                }
                if ($col->comment) {
                    $attr[] = $col->comment;
                }

                $out[] = sprintf(
                    '| %d | `%s` | `%s` | %s | %s | %s | %s |',
                    $pos++,
                    $col->name,
                    $col->dataType,
                    $col->nullable ? 'YES' : 'NO',
                    $col->defaultValue !== null ? '`' . $col->defaultValue . '`' : 'NULL',
                    $key,
                    implode(', ', $attr)
                );
            }
            $out[] = "";

            // Indexes
            if (!empty($table->indexes)) {
                $out[] = "#### Indexes";
                $out[] = "| Index Name | Columns | Unique | Primary | Type |";
                $out[] = "| :--- | :--- | :---: | :---: | :--- |";
                foreach ($table->indexes as $idx) {
                    $out[] = sprintf(
                        '| `%s` | `%s` | %s | %s | %s |',
                        $idx->name,
                        implode(', ', $idx->columns),
                        $idx->isUnique ? 'YES' : 'NO',
                        $idx->isPrimary ? 'YES' : 'NO',
                        $idx->type
                    );
                }
                $out[] = "";
            }

            // Foreign Keys
            if (!empty($table->foreignKeys)) {
                $out[] = "#### Foreign Keys";
                $out[] = "| Constraint | Local Column(s) | Referenced Table | Referenced Column(s) | On Delete | On Update |";
                $out[] = "| :--- | :--- | :--- | :--- | :--- | :--- |";
                foreach ($table->foreignKeys as $fk) {
                    $out[] = sprintf(
                        '| `%s` | `%s` | `%s` | `%s` | %s | %s |',
                        $fk->name,
                        implode(', ', $fk->columns),
                        $fk->referencedTable,
                        implode(', ', $fk->referencedColumns),
                        $fk->onDelete,
                        $fk->onUpdate
                    );
                }
                $out[] = "";
            }
        }

        // Inferred Relations Section
        if (!empty($report->inferredRelations)) {
            $out[] = "## Inferred Relationships";
            $out[] = "| Source Table.Column | Target Table.Column | Cardinality | Confidence | Reason |";
            $out[] = "| :--- | :--- | :---: | :---: | :--- |";
            foreach ($report->inferredRelations as $rel) {
                $out[] = sprintf(
                    '| `%s.%s` | `%s.%s` | %s | **%s** | %s |',
                    $rel->sourceTable,
                    $rel->sourceColumn,
                    $rel->targetTable,
                    $rel->targetColumn,
                    $rel->cardinality,
                    $rel->confidence,
                    $rel->reason
                );
            }
            $out[] = "";
        }

        // Missing Index Advisory Section
        if (!empty($report->missingIndexes)) {
            $out[] = "## Missing Index Advisories";
            $out[] = "| Table | Column(s) | Severity | Reason | Suggested DDL |";
            $out[] = "| :--- | :--- | :---: | :--- | :--- |";
            foreach ($report->missingIndexes as $idx) {
                $out[] = sprintf(
                    '| `%s` | `%s` | **%s** | %s | `%s` |',
                    $idx->table,
                    implode(', ', $idx->columns),
                    $idx->severity,
                    $idx->reason,
                    $idx->suggestedSql
                );
            }
            $out[] = "";
        }

        return implode("\n", $out) . "\n";
    }
}
