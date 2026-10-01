<?php

declare(strict_types=1);

namespace EidCloud\SchemaIntel\Model;

class InferredRelation implements \JsonSerializable
{
    public function __construct(
        public string $sourceTable,
        public string $sourceColumn,
        public string $targetTable,
        public string $targetColumn,
        public string $confidence = 'HIGH', // HIGH, MEDIUM, LOW
        public string $reason = '',
        public string $cardinality = 'N:1' // 1:1, 1:N, N:1
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'sourceTable' => $this->sourceTable,
            'sourceColumn' => $this->sourceColumn,
            'targetTable' => $this->targetTable,
            'targetColumn' => $this->targetColumn,
            'confidence' => $this->confidence,
            'reason' => $this->reason,
            'cardinality' => $this->cardinality,
        ];
    }
}
