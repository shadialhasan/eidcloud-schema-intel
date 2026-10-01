<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/SchemaIntelTest.php';

use EidCloud\SchemaIntel\Tests\SchemaIntelTest;

echo "========================================\n";
echo "  eidcloud-schema-intel Test Runner     \n";
echo "========================================\n";

try {
    $suite = new SchemaIntelTest();
    $suite->runAll();
    exit(0);
} catch (\Throwable $e) {
    echo "\n❌ Test Failure: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
