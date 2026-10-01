<?php

declare(strict_types=1);

namespace EidCloud\SchemaIntel;

use EidCloud\SchemaIntel\Drivers\DatabaseDriverInterface;
use EidCloud\SchemaIntel\Drivers\MysqlDriver;
use EidCloud\SchemaIntel\Drivers\SqliteDriver;
use EidCloud\SchemaIntel\Exporter\MarkdownDataDictionaryExporter;
use EidCloud\SchemaIntel\Exporter\MermaidExporter;
use EidCloud\SchemaIntel\Inference\IndexAdvisor;
use EidCloud\SchemaIntel\Inference\RelationInferrer;
use EidCloud\SchemaIntel\Model\SchemaIntelReport;
use PDO;

class SchemaInspector
{
    private DatabaseDriverInterface $driver;
    private RelationInferrer $relationInferrer;
    private IndexAdvisor $indexAdvisor;
    private MermaidExporter $mermaidExporter;
    private MarkdownDataDictionaryExporter $dataDictionaryExporter;

    public function __construct(
        DatabaseDriverInterface $driver,
        ?RelationInferrer $relationInferrer = null,
        ?IndexAdvisor $indexAdvisor = null,
        ?MermaidExporter $mermaidExporter = null,
        ?MarkdownDataDictionaryExporter $dataDictionaryExporter = null
    ) {
        $this->driver = $driver;
        $this->relationInferrer = $relationInferrer ?? new RelationInferrer();
        $this->indexAdvisor = $indexAdvisor ?? new IndexAdvisor();
        $this->mermaidExporter = $mermaidExporter ?? new MermaidExporter();
        $this->dataDictionaryExporter = $dataDictionaryExporter ?? new MarkdownDataDictionaryExporter();
    }

    public static function fromSqlite(string|PDO $sqliteFileOrPdo): self
    {
        return new self(new SqliteDriver($sqliteFileOrPdo));
    }

    public static function fromMysql(PDO|array $mysqlConfigOrPdo): self
    {
        return new self(new MysqlDriver($mysqlConfigOrPdo));
    }

    public function getDriver(): DatabaseDriverInterface
    {
        return $this->driver;
    }

    /**
     * Inspect database schema, infer relations, and detect missing indexes
     */
    public function inspect(array $metadata = []): SchemaIntelReport
    {
        $tables = $this->driver->inspectAll();
        $inferredRelations = $this->relationInferrer->inferRelations($tables);
        $missingIndexes = $this->indexAdvisor->detectMissingIndexes($tables, $inferredRelations, $this->driver);

        return new SchemaIntelReport(
            databaseName: $this->driver->getDatabaseName(),
            driverName: $this->driver->getName(),
            tables: $tables,
            inferredRelations: $inferredRelations,
            missingIndexes: $missingIndexes,
            metadata: $metadata
        );
    }

    /**
     * Generate Mermaid ER diagram
     */
    public function toMermaid(?SchemaIntelReport $report = null, bool $includeInferred = true): string
    {
        $report ??= $this->inspect();
        return $this->mermaidExporter->export($report, $includeInferred);
    }

    /**
     * Generate AI-ready JSON schema
     */
    public function toJson(?SchemaIntelReport $report = null, int $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES): string
    {
        $report ??= $this->inspect();
        return json_encode($report, $flags) ?: '{}';
    }

    /**
     * Generate Markdown data dictionary
     */
    public function toDataDictionary(?SchemaIntelReport $report = null): string
    {
        $report ??= $this->inspect();
        return $this->dataDictionaryExporter->export($report);
    }
}
