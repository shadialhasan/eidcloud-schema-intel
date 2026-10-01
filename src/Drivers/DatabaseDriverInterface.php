<?php

declare(strict_types=1);

namespace EidCloud\SchemaIntel\Drivers;

use EidCloud\SchemaIntel\Model\TableDefinition;

interface DatabaseDriverInterface
{
    public function getName(): string;

    public function getDatabaseName(): string;

    /**
     * @return string[]
     */
    public function listTableNames(): array;

    /**
     * @return array<string, TableDefinition>
     */
    public function inspectAll(): array;

    public function inspectTable(string $tableName): TableDefinition;

    public function generateCreateIndexSql(string $table, string $indexName, array $columns): string;
}
