<?php

declare(strict_types=1);

namespace EidCloud\SchemaIntel\Model;

class IndexDefinition implements \JsonSerializable
{
    /**
     * @param string[] $columns
     */
    public function __construct(
        public string $name,
        public array $columns,
        public bool $isUnique = false,
        public bool $isPrimary = false,
        public ?string $type = 'BTREE'
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'columns' => $this->columns,
            'isUnique' => $this->isUnique,
            'isPrimary' => $this->isPrimary,
            'type' => $this->type,
        ];
    }
}
