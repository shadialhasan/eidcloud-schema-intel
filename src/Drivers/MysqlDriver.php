<?php

declare(strict_types=1);

namespace EidCloud\SchemaIntel\Drivers;

use EidCloud\SchemaIntel\Model\ColumnDefinition;
use EidCloud\SchemaIntel\Model\ForeignKeyDefinition;
use EidCloud\SchemaIntel\Model\IndexDefinition;
use EidCloud\SchemaIntel\Model\TableDefinition;
use PDO;

class MysqlDriver implements DatabaseDriverInterface
{
    private PDO $pdo;
    private string $database;

    public function __construct(PDO|array $pdoOrConfig)
    {
        if ($pdoOrConfig instanceof PDO) {
            $this->pdo = $pdoOrConfig;
            $dbStmt = $this->pdo->query('SELECT DATABASE()');
            $this->database = (string)($dbStmt ? $dbStmt->fetchColumn() : 'default');
        } else {
            $host = $pdoOrConfig['host'] ?? '127.0.0.1';
            $port = $pdoOrConfig['port'] ?? 3306;
            $this->database = $pdoOrConfig['database'] ?? '';
            $user = $pdoOrConfig['username'] ?? 'root';
            $pass = $pdoOrConfig['password'] ?? '';
            $charset = $pdoOrConfig['charset'] ?? 'utf8mb4';

            $dsn = "mysql:host={$host};port={$port};dbname={$this->database};charset={$charset}";
            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }
    }

    public function getName(): string
    {
        return 'MySQL/MariaDB';
    }

    public function getDatabaseName(): string
    {
        return $this->database;
    }

    public function listTableNames(): array
    {
        $stmt = $this->pdo->prepare("
            SELECT TABLE_NAME
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = :schema AND TABLE_TYPE = 'BASE TABLE'
            ORDER BY TABLE_NAME
        ");
        $stmt->execute(['schema' => $this->database]);
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
        // Table metadata
        $metaStmt = $this->pdo->prepare("
            SELECT TABLE_ROWS, TABLE_COMMENT, ENGINE
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table
        ");
        $metaStmt->execute(['schema' => $this->database, 'table' => $tableName]);
        $meta = $metaStmt->fetch() ?: [];

        $table = new TableDefinition(
            name: $tableName,
            rowCount: isset($meta['TABLE_ROWS']) ? (int)$meta['TABLE_ROWS'] : null,
            comment: !empty($meta['TABLE_COMMENT']) ? $meta['TABLE_COMMENT'] : null,
            engine: $meta['ENGINE'] ?? 'InnoDB'
        );

        // Columns
        $colStmt = $this->pdo->prepare("
            SELECT
                COLUMN_NAME,
                DATA_TYPE,
                COLUMN_TYPE,
                IS_NULLABLE,
                COLUMN_DEFAULT,
                COLUMN_KEY,
                EXTRA,
                COLUMN_COMMENT,
                ORDINAL_POSITION
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table
            ORDER BY ORDINAL_POSITION
        ");
        $colStmt->execute(['schema' => $this->database, 'table' => $tableName]);
        $columns = $colStmt->fetchAll();

        foreach ($columns as $col) {
            $columnDef = new ColumnDefinition(
                name: $col['COLUMN_NAME'],
                dataType: strtoupper($col['COLUMN_TYPE'] ?: $col['DATA_TYPE']),
                nullable: strtoupper($col['IS_NULLABLE']) === 'YES',
                defaultValue: $col['COLUMN_DEFAULT'],
                isPrimaryKey: strtoupper($col['COLUMN_KEY']) === 'PRI',
                isAutoIncrement: stripos($col['EXTRA'] ?? '', 'auto_increment') !== false,
                comment: !empty($col['COLUMN_COMMENT']) ? $col['COLUMN_COMMENT'] : null,
                ordinalPosition: (int)$col['ORDINAL_POSITION']
            );
            $table->addColumn($columnDef);
        }

        // Indexes
        $idxStmt = $this->pdo->prepare("
            SELECT
                INDEX_NAME,
                NON_UNIQUE,
                COLUMN_NAME,
                INDEX_TYPE,
                SEQ_IN_INDEX
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table
            ORDER BY INDEX_NAME, SEQ_IN_INDEX
        ");
        $idxStmt->execute(['schema' => $this->database, 'table' => $tableName]);
        $idxRows = $idxStmt->fetchAll();

        $indexes = [];
        foreach ($idxRows as $row) {
            $name = $row['INDEX_NAME'];
            if (!isset($indexes[$name])) {
                $indexes[$name] = [
                    'columns' => [],
                    'isUnique' => (int)$row['NON_UNIQUE'] === 0,
                    'isPrimary' => $name === 'PRIMARY',
                    'type' => $row['INDEX_TYPE'],
                ];
            }
            $indexes[$name]['columns'][] = $row['COLUMN_NAME'];
        }

        foreach ($indexes as $name => $idxData) {
            $indexDef = new IndexDefinition(
                name: $name,
                columns: $idxData['columns'],
                isUnique: $idxData['isUnique'],
                isPrimary: $idxData['isPrimary'],
                type: $idxData['type']
            );
            $table->addIndex($indexDef);
        }

        // Foreign Keys
        $fkStmt = $this->pdo->prepare("
            SELECT
                kcu.CONSTRAINT_NAME,
                kcu.COLUMN_NAME,
                kcu.REFERENCED_TABLE_NAME,
                kcu.REFERENCED_COLUMN_NAME,
                rc.UPDATE_RULE,
                rc.DELETE_RULE
            FROM information_schema.KEY_COLUMN_USAGE kcu
            LEFT JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
                ON kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
                AND kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
            WHERE kcu.TABLE_SCHEMA = :schema
                AND kcu.TABLE_NAME = :table
                AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
            ORDER BY kcu.CONSTRAINT_NAME, kcu.ORDINAL_POSITION
        ");
        $fkStmt->execute(['schema' => $this->database, 'table' => $tableName]);
        $fkRows = $fkStmt->fetchAll();

        $fks = [];
        foreach ($fkRows as $row) {
            $cName = $row['CONSTRAINT_NAME'];
            if (!isset($fks[$cName])) {
                $fks[$cName] = [
                    'columns' => [],
                    'referencedTable' => $row['REFERENCED_TABLE_NAME'],
                    'referencedColumns' => [],
                    'onUpdate' => $row['UPDATE_RULE'] ?? 'NO ACTION',
                    'onDelete' => $row['DELETE_RULE'] ?? 'NO ACTION',
                ];
            }
            $fks[$cName]['columns'][] = $row['COLUMN_NAME'];
            $fks[$cName]['referencedColumns'][] = $row['REFERENCED_COLUMN_NAME'];
        }

        foreach ($fks as $name => $fkData) {
            $fkDef = new ForeignKeyDefinition(
                name: $name,
                columns: $fkData['columns'],
                referencedTable: $fkData['referencedTable'],
                referencedColumns: $fkData['referencedColumns'],
                onUpdate: $fkData['onUpdate'],
                onDelete: $fkData['onDelete'],
                isExplicit: true
            );
            $table->addForeignKey($fkDef);
        }

        return $table;
    }

    public function generateCreateIndexSql(string $table, string $indexName, array $columns): string
    {
        $cols = implode(', ', array_map(fn($c) => "`{$c}`", $columns));
        return "CREATE INDEX `{$indexName}` ON `{$table}` ({$cols});";
    }
}
