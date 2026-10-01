<?php

declare(strict_types=1);

namespace EidCloud\SchemaIntel\Exporter;

use EidCloud\SchemaIntel\Model\SchemaIntelReport;

class MermaidExporter
{
    /**
     * Export database schema into a Mermaid erDiagram string
     */
    public function export(SchemaIntelReport $report, bool $includeInferred = true): string
    {
        $lines = [];
        $lines[] = 'erDiagram';

        // 1. Render relationships
        $renderedPairs = [];

        // Explicit foreign keys
        foreach ($report->tables as $table) {
            foreach ($table->foreignKeys as $fk) {
                $source = $this->sanitizeName($table->name);
                $target = $this->sanitizeName($fk->referencedTable);
                $label = implode(',', $fk->columns);

                $key = "{$source}->{$target}:{$label}";
                if (!isset($renderedPairs[$key])) {
                    $lines[] = "    {$target} ||--o{ {$source} : \"{$label}\"";
                    $renderedPairs[$key] = true;
                }
            }
        }

        // Inferred relations
        if ($includeInferred) {
            foreach ($report->inferredRelations as $rel) {
                $source = $this->sanitizeName($rel->sourceTable);
                $target = $this->sanitizeName($rel->targetTable);
                $label = $rel->sourceColumn . ' (inferred)';

                $key = "{$source}->{$target}:{$rel->sourceColumn}";
                if (!isset($renderedPairs[$key])) {
                    if ($rel->cardinality === '1:1') {
                        $lines[] = "    {$target} ||--|| {$source} : \"{$label}\"";
                    } else {
                        $lines[] = "    {$target} ||--o{ {$source} : \"{$label}\"";
                    }
                    $renderedPairs[$key] = true;
                }
            }
        }

        // 2. Render table definitions with columns and constraints
        foreach ($report->tables as $table) {
            $tableName = $this->sanitizeName($table->name);
            $lines[] = "    {$tableName} {";

            foreach ($table->columns as $col) {
                $type = $this->cleanType($col->dataType);
                $name = $this->sanitizeName($col->name);
                $keyMarker = '';

                if ($col->isPrimaryKey) {
                    $keyMarker = ' PK';
                } elseif ($this->isForeignKeyCol($table, $col->name, $report->inferredRelations, $includeInferred)) {
                    $keyMarker = ' FK';
                }

                $comment = $col->nullable ? '' : '"not null"';
                if ($keyMarker !== '') {
                    $lines[] = "        {$type} {$name}{$keyMarker} {$comment}";
                } else {
                    $lines[] = "        {$type} {$name} {$comment}";
                }
            }

            $lines[] = '    }';
        }

        return implode("\n", $lines) . "\n";
    }

    private function sanitizeName(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_]/', '_', $name);
    }

    private function cleanType(string $type): string
    {
        // Strip parameters: VARCHAR(255) -> string, INT(11) -> int
        $clean = preg_replace('/\(.*?\)/', '', $type);
        $clean = strtolower(trim($clean));

        return match ($clean) {
            'int', 'integer', 'tinyint', 'smallint', 'mediumint', 'bigint' => 'int',
            'varchar', 'char', 'text', 'tinytext', 'mediumtext', 'longtext', 'string' => 'string',
            'float', 'double', 'decimal', 'numeric', 'real' => 'float',
            'bool', 'boolean' => 'boolean',
            'datetime', 'timestamp', 'date', 'time', 'year' => 'datetime',
            'json' => 'json',
            default => !empty($clean) ? preg_replace('/[^a-zA-Z0-9]/', '', $clean) : 'text',
        };
    }

    private function isForeignKeyCol($table, string $colName, array $inferred, bool $includeInferred): bool
    {
        foreach ($table->foreignKeys as $fk) {
            if (in_array($colName, $fk->columns, true)) {
                return true;
            }
        }

        if ($includeInferred) {
            foreach ($inferred as $rel) {
                if (strtolower($rel->sourceTable) === strtolower($table->name) && strtolower($rel->sourceColumn) === strtolower($colName)) {
                    return true;
                }
            }
        }

        return false;
    }
}
