<?php

declare(strict_types=1);

namespace EidCloud\SchemaIntel\Model;

class ColumnDefinition implements \JsonSerializable
{
    public function __construct(
        public string $name,
        public string $dataType,
        public bool $nullable = true,
        public ?string $defaultValue = null,
        public bool $isPrimaryKey = false,
        public bool $isAutoIncrement = false,
        public ?string $comment = null,
        public ?int $ordinalPosition = null
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'dataType' => $this->dataType,
            'nullable' => $this->nullable,
            'defaultValue' => $this->defaultValue,
            'isPrimaryKey' => $this->isPrimaryKey,
            'isAutoIncrement' => $this->isAutoIncrement,
            'comment' => $this->comment,
            'ordinalPosition' => $this->ordinalPosition,
        ];
    }
}
