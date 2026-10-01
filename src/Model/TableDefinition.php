<?php

declare(strict_types=1);

namespace EidCloud\SchemaIntel\Model;

class TableDefinition implements \JsonSerializable
{
    /**
     * @param array<string, ColumnDefinition> $columns
     * @param array<string, IndexDefinition> $indexes
     * @param ForeignKeyDefinition[] $foreignKeys
     */
    public function __construct(
        public string $name,
        public array $columns = [],
        public array $indexes = [],
        public array $foreignKeys = [],
        public ?int $rowCount = null,
        public ?string $comment = null,
        public ?string $engine = null
    ) {
    }

    public function addColumn(ColumnDefinition $column): self
    {
        $this->columns[$column->name] = $column;
        return $this;
    }

    public function addIndex(IndexDefinition $index): self
    {
        $this->indexes[$index->name] = $index;
        return $this;
    }

    public function addForeignKey(ForeignKeyDefinition $foreignKey): self
    {
        $this->foreignKeys[] = $foreignKey;
        return $this;
    }

    /**
     * @return string[]
     */
    public function getPrimaryKeyColumns(): array
    {
        $pks = [];
        foreach ($this->columns as $col) {
            if ($col->isPrimaryKey) {
                $pks[] = $col->name;
            }
        }
        return $pks;
    }

    public function hasIndexLeadingWith(string $column): bool
    {
        foreach ($this->indexes as $index) {
            if (!empty($index->columns) && strtolower($index->columns[0]) === strtolower($column)) {
                return true;
            }
        }
        return false;
    }

    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'columns' => array_values($this->columns),
            'indexes' => array_values($this->indexes),
            'foreignKeys' => $this->foreignKeys,
            'rowCount' => $this->rowCount,
            'comment' => $this->comment,
            'engine' => $this->engine,
        ];
    }
}
