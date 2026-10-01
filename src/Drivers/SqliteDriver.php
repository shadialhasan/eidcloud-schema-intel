<?php

declare(strict_types=1);

namespace EidCloud\SchemaIntel\Drivers;

use EidCloud\SchemaIntel\Model\ColumnDefinition;
use EidCloud\SchemaIntel\Model\ForeignKeyDefinition;
use EidCloud\SchemaIntel\Model\IndexDefinition;
use EidCloud\SchemaIntel\Model\TableDefinition;
use PDO;

class SqliteDriver implements DatabaseDriverInterface
{
    private PDO $pdo;
    private string $dbPath;

    public function __construct(PDO|string $pdoOrPath)
    {
        if ($pdoOrPath instanceof PDO) {
            $this->pdo = $pdoOrPath;
            $this->dbPath = ':memory:';
        } else {
            $this->dbPath = $pdoOrPath;
            $this->pdo = new PDO('sqlite:' . $pdoOrPath);
        }
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function getName(): string
    {
        return 'SQLite';
    }

    public function getDatabaseName(): string
    {
        return basename($this->dbPath);
    }

    public function listTableNames(): array
    {
        $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    public function inspectAll(): array
    {
        $tables = [];
        foreach ($this->listTableNames() as $tableName) {
            $tables[$tableName] = $this->inspectTable($tableName);
        }
        return $tables;
    }

    public function inspectTable(string $tableName): TableDefinition
    {
        $escapedTableName = str_replace("'", "''", $tableName);

        // Fetch row count
        $rowCount = null;
        try {
            $countStmt = $this->pdo->query("SELECT COUNT(*) FROM \"{$tableName}\"");
            if ($countStmt) {
                $rowCount = (int)$countStmt->fetchColumn();
            }
        } catch (\Throwable) {
            // Table might be locked or virtual
        }

        $table = new TableDefinition(
            name: $tableName,
            rowCount: $rowCount,
            engine: 'SQLite'
        );

        // Columns via PRAGMA table_info
        $colStmt = $this->pdo->query("PRAGMA table_info(\"{$tableName}\")");
        $columns = $colStmt->fetchAll(PDO::FETCH_ASSOC);

        // Check if there is an autoincrement primary key by looking at sqlite_master SQL
        $sqlStmt = $this->pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name = '{$escapedTableName}'");
        $tableSql = $sqlStmt->fetchColumn() ?: '';

        foreach ($columns as $col) {
            $colName = $col['name'];
            $colType = $col['type'] ?: 'TEXT';
            $notNull = (bool)$col['notnull'];
            $defaultVal = $col['dflt_value'];
            $isPk = (int)$col['pk'] > 0;

            // Check auto increment
            $isAutoIncrement = false;
            if ($isPk && stripos($tableSql, 'AUTOINCREMENT') !== false) {
                // If it explicitly says AUTOINCREMENT or is INTEGER PRIMARY KEY
                if (preg_match('/' . preg_quote($colName, '/') . '\s+INTEGER\s+PRIMARY\s+KEY\s+AUTOINCREMENT/i', $tableSql)
                    || preg_match('/' . preg_quote($colName, '/') . '\s+INTEGER\s+PRIMARY\s+KEY/i', $tableSql)) {
                    $isAutoIncrement = true;
                }
            } elseif ($isPk && strtoupper($colType) === 'INTEGER') {
                $isAutoIncrement = true;
            }

            $columnDef = new ColumnDefinition(
                name: $colName,
                dataType: strtoupper($colType),
                nullable: !$notNull,
                defaultValue: $defaultVal,
                isPrimaryKey: $isPk,
                isAutoIncrement: $isAutoIncrement,
                ordinalPosition: (int)$col['cid'] + 1
            );
            $table->addColumn($columnDef);
        }

        // Indexes via PRAGMA index_list
        $idxStmt = $this->pdo->query("PRAGMA index_list(\"{$tableName}\")");
        $indexes = $idxStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($indexes as $idx) {
            $idxName = $idx['name'];
            $isUnique = (bool)$idx['unique'];
            $origin = $idx['origin'] ?? '';
            $isPk = ($origin === 'pk');

            $infoStmt = $this->pdo->query("PRAGMA index_info(\"{$idxName}\")");
            $idxColsData = $infoStmt->fetchAll(PDO::FETCH_ASSOC);
            $idxCols = array_map(fn($c) => $c['name'], $idxColsData);

            $indexDef = new IndexDefinition(
                name: $idxName,
                columns: $idxCols,
                isUnique: $isUnique,
                isPrimary: $isPk,
                type: 'BTREE'
            );
            $table->addIndex($indexDef);
        }

        // Explicit Foreign Keys via PRAGMA foreign_key_list
        $fkStmt = $this->pdo->query("PRAGMA foreign_key_list(\"{$tableName}\")");
        $fks = $fkStmt->fetchAll(PDO::FETCH_ASSOC);

        // Group FKs by id
        $groupedFks = [];
        foreach ($fks as $fk) {
            $fkId = $fk['id'];
            if (!isset($groupedFks[$fkId])) {
                $groupedFks[$fkId] = [
                    'table' => $fk['table'],
                    'from' => [],
                    'to' => [],
                    'on_update' => $fk['on_update'],
                    'on_delete' => $fk['on_delete'],
                ];
            }
            $groupedFks[$fkId]['from'][] = $fk['from'];
            $groupedFks[$fkId]['to'][] = $fk['to'];
        }

        foreach ($groupedFks as $id => $fkData) {
            $fkName = sprintf('fk_%s_%s_%s', $tableName, $fkData['table'], implode('_', $fkData['from']));
            $fkDef = new ForeignKeyDefinition(
                name: $fkName,
                columns: $fkData['from'],
                referencedTable: $fkData['table'],
                referencedColumns: $fkData['to'],
                onUpdate: $fkData['on_update'],
                onDelete: $fkData['on_delete'],
                isExplicit: true
            );
            $table->addForeignKey($fkDef);
        }

        return $table;
    }

    public function generateCreateIndexSql(string $table, string $indexName, array $columns): string
    {
        $cols = implode(', ', array_map(fn($c) => "\"{$c}\"", $columns));
        return "CREATE INDEX \"{$indexName}\" ON \"{$table}\" ({$cols});";
    }
}
