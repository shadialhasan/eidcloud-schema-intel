<?php

declare(strict_types=1);

namespace EidCloud\SchemaIntel\Model;

class SchemaIntelReport implements \JsonSerializable
{
    /**
     * @param array<string, TableDefinition> $tables
     * @param InferredRelation[] $inferredRelations
     * @param MissingIndexSuggestion[] $missingIndexes
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $databaseName,
        public string $driverName,
        public array $tables = [],
        public array $inferredRelations = [],
        public array $missingIndexes = [],
        public array $metadata = []
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'database' => $this->databaseName,
            'driver' => $this->driverName,
            'generatedAt' => date('c'),
            'summary' => [
                'totalTables' => count($this->tables),
                'totalColumns' => array_sum(array_map(fn($t) => count($t->columns), $this->tables)),
                'explicitForeignKeys' => array_sum(array_map(fn($t) => count($t->foreignKeys), $this->tables)),
                'inferredRelations' => count($this->inferredRelations),
                'missingIndexes' => count($this->missingIndexes),
            ],
            'tables' => array_values($this->tables),
            'inferredRelations' => $this->inferredRelations,
            'missingIndexes' => $this->missingIndexes,
            'metadata' => $this->metadata,
        ];
    }
}
