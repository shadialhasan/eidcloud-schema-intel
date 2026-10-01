<?php

declare(strict_types=1);

namespace EidCloud\SchemaIntel\Model;

class MissingIndexSuggestion implements \JsonSerializable
{
    /**
     * @param string[] $columns
     */
    public function __construct(
        public string $table,
        public array $columns,
        public string $reason,
        public string $severity = 'HIGH', // CRITICAL, HIGH, MEDIUM, LOW
        public string $suggestedIndexName = '',
        public string $suggestedSql = ''
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'table' => $this->table,
            'columns' => $this->columns,
            'reason' => $this->reason,
            'severity' => $this->severity,
            'suggestedIndexName' => $this->suggestedIndexName,
            'suggestedSql' => $this->suggestedSql,
        ];
    }
}
