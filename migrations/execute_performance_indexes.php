<?php

/**
 * Script to execute performance optimization indexes migration
 * Task 17.1: Add database indexes for query optimization
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use DoctrineMigrations\Version20260427120002;

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Database connection parameters
$connectionParams = [
    'dbname' => $_ENV['DB_NAME'] ?? 'optimus_db',
    'user' => $_ENV['DB_USER'] ?? 'root',
    'password' => $_ENV['DB_PASSWORD'] ?? '',
    'host' => $_ENV['DB_HOST'] ?? 'localhost',
    'driver' => 'pdo_mysql',
    'charset' => 'utf8mb4',
];

try {
    echo "Connecting to database...\n";
    $connection = DriverManager::getConnection($connectionParams);
    
    echo "Executing performance optimization indexes migration...\n\n";
    
    // Create migration instance
    $migration = new Version20260427120002($connection);
    
    // Execute the migration
    $schema = $connection->createSchemaManager()->introspectSchema();
    $migration->up($schema);
    
    echo "✓ Performance indexes created successfully!\n\n";
    
    // Verify indexes were created
    echo "Verifying indexes...\n";
    
    $indexes = [
        'containers' => ['IDX_CONTAINERS_CY_ALLOCATION_STATUS'],
        'container_allocation_audit' => ['IDX_AUDIT_CONTAINER_CHANGED_AT'],
        'shipping_line_terminal_allocations' => ['IDX_SLTA_SHIPPING_LINE_TERMINAL']
    ];
    
    foreach ($indexes as $table => $indexNames) {
        $tableIndexes = $connection->createSchemaManager()->listTableIndexes($table);
        foreach ($indexNames as $indexName) {
            $found = false;
            foreach ($tableIndexes as $index) {
                if (strtoupper($index->getName()) === strtoupper($indexName)) {
                    $found = true;
                    $columns = implode(', ', $index->getColumns());
                    echo "  ✓ Index {$indexName} on {$table} ({$columns})\n";
                    break;
                }
            }
            if (!$found) {
                echo "  ✗ Index {$indexName} on {$table} NOT FOUND\n";
            }
        }
    }
    
    echo "\n✓ Migration completed successfully!\n";
    
} catch (\Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
