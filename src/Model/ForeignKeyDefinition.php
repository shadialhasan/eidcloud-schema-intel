<?php

declare(strict_types=1);

namespace EidCloud\SchemaIntel\Model;

class ForeignKeyDefinition implements \JsonSerializable
{
    /**
     * @param string[] $columns
     * @param string[] $referencedColumns
     */
    public function __construct(
        public string $name,
        public array $columns,
        public string $referencedTable,
        public array $referencedColumns,
        public ?string $onUpdate = 'NO ACTION',
        public ?string $onDelete = 'NO ACTION',
        public bool $isExplicit = true
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'columns' => $this->columns,
            'referencedTable' => $this->referencedTable,
            'referencedColumns' => $this->referencedColumns,
            'onUpdate' => $this->onUpdate,
            'onDelete' => $this->onDelete,
            'isExplicit' => $this->isExplicit,
        ];
    }
}
