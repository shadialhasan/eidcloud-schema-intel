<?php

declare(strict_types=1);

namespace EidCloud\SchemaIntel\Tests;

use EidCloud\SchemaIntel\Drivers\SqliteDriver;
use EidCloud\SchemaIntel\Inference\IndexAdvisor;
use EidCloud\SchemaIntel\Inference\RelationInferrer;
use EidCloud\SchemaIntel\SchemaInspector;
use PDO;

class SchemaIntelTest
{
    private PDO $pdo;

    public function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Setup test schema
        $this->pdo->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL,
                name TEXT NOT NULL,
                status TEXT NOT NULL,
                created_at TEXT NOT NULL
            );

            CREATE TABLE categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                parent_id INTEGER,
                slug TEXT NOT NULL
            );

            CREATE TABLE orders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                order_no TEXT NOT NULL,
                total_amount REAL NOT NULL,
                created_by INTEGER,
                created_at TEXT NOT NULL
            );

            CREATE TABLE order_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                order_id INTEGER NOT NULL,
                product_name TEXT NOT NULL,
                unit_price REAL NOT NULL,
                quantity INTEGER NOT NULL,
                FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
            );
        ");
    }

    public function testIntrospection(): void
    {
        $driver = new SqliteDriver($this->pdo);
        $tables = $driver->inspectAll();

        assert(count($tables) === 4, "Expected 4 tables, got " . count($tables));
        assert(isset($tables['users']), "Table 'users' should exist");
        assert(isset($tables['categories']), "Table 'categories' should exist");
        assert(isset($tables['orders']), "Table 'orders' should exist");
        assert(isset($tables['order_items']), "Table 'order_items' should exist");

        $orderItems = $tables['order_items'];
        assert(count($orderItems->foreignKeys) === 1, "order_items should have 1 explicit foreign key");
        assert($orderItems->foreignKeys[0]->referencedTable === 'orders', "FK referenced table should be 'orders'");

        echo "  [PASS] testIntrospection\n";
    }

    public function testRelationInference(): void
    {
        $driver = new SqliteDriver($this->pdo);
        $tables = $driver->inspectAll();

        $inferrer = new RelationInferrer();
        $relations = $inferrer->inferRelations($tables);

        // Check self-referential parent_id on categories
        $selfRef = array_filter($relations, fn($r) => $r->sourceTable === 'categories' && $r->sourceColumn === 'parent_id');
        assert(count($selfRef) === 1, "Should infer parent_id relation on categories");
        $firstSelf = reset($selfRef);
        assert($firstSelf->targetTable === 'categories', "Target of parent_id should be categories");

        // Check orders.user_id -> users.id
        $userRef = array_filter($relations, fn($r) => $r->sourceTable === 'orders' && $r->sourceColumn === 'user_id');
        assert(count($userRef) === 1, "Should infer orders.user_id -> users.id");
        $firstUserRef = reset($userRef);
        assert($firstUserRef->targetTable === 'users' && $firstUserRef->targetColumn === 'id');

        // Check orders.created_by -> users.id
        $createdByRef = array_filter($relations, fn($r) => $r->sourceTable === 'orders' && $r->sourceColumn === 'created_by');
        assert(count($createdByRef) === 1, "Should infer orders.created_by -> users.id");

        echo "  [PASS] testRelationInference\n";
    }

    public function testMissingIndexDetection(): void
    {
        $driver = new SqliteDriver($this->pdo);
        $tables = $driver->inspectAll();

        $inferrer = new RelationInferrer();
        $relations = $inferrer->inferRelations($tables);

        $advisor = new IndexAdvisor();
        $suggestions = $advisor->detectMissingIndexes($tables, $relations, $driver);

        // order_items.order_id has an explicit FK with NO index -> CRITICAL missing index
        $fkIndex = array_filter($suggestions, fn($s) => $s->table === 'order_items' && in_array('order_id', $s->columns));
        assert(!empty($fkIndex), "Should detect missing index on explicit foreign key order_items.order_id");

        // orders.user_id has inferred relation -> HIGH missing index
        $userIndex = array_filter($suggestions, fn($s) => $s->table === 'orders' && in_array('user_id', $s->columns));
        assert(!empty($userIndex), "Should detect missing index on inferred relation orders.user_id");

        // users.email has common query pattern -> HIGH missing index
        $emailIndex = array_filter($suggestions, fn($s) => $s->table === 'users' && in_array('email', $s->columns));
        assert(!empty($emailIndex), "Should detect missing index on users.email");

        echo "  [PASS] testMissingIndexDetection\n";
    }

    public function testMermaidAndJsonExport(): void
    {
        $inspector = new SchemaInspector(new SqliteDriver($this->pdo));
        $report = $inspector->inspect();

        $json = $inspector->toJson($report);
        $decoded = json_decode($json, true);
        assert(json_last_error() === JSON_ERROR_NONE, "JSON export should be valid JSON");
        assert(isset($decoded['summary']), "Report summary should exist");
        assert($decoded['summary']['totalTables'] === 4, "Report summary totalTables should be 4");

        $mermaid = $inspector->toMermaid($report);
        assert(str_starts_with($mermaid, 'erDiagram'), "Mermaid export should start with 'erDiagram'");
        assert(str_contains($mermaid, 'users {'), "Mermaid export should contain users entity");
        assert(str_contains($mermaid, 'orders {'), "Mermaid export should contain orders entity");
        assert(str_contains($mermaid, 'order_items {'), "Mermaid export should contain order_items entity");

        $markdown = $inspector->toDataDictionary($report);
        assert(str_contains($markdown, '# Data Dictionary'), "Markdown dictionary should have header");
        assert(str_contains($markdown, '### Table: `users`'), "Markdown dictionary should list users table");

        echo "  [PASS] testMermaidAndJsonExport\n";
    }

    public function runAll(): void
    {
        echo "Running SchemaIntelTest suite...\n";
        $this->setUp();
        $this->testIntrospection();
        $this->testRelationInference();
        $this->testMissingIndexDetection();
        $this->testMermaidAndJsonExport();
        echo "✅ All tests passed successfully!\n";
    }
}
