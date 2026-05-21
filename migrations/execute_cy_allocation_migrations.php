<?php

/**
 * Script to execute CY allocation migrations
 * 
 * This script runs the database migrations for per-container CY allocation feature:
 * - Version20260427120000: Add CY allocation fields to containers table
 * - Version20260427120001: Create container_allocation_audit table
 * 
 * Usage: php migrations/execute_cy_allocation_migrations.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use Symfony\Component\Dotenv\Dotenv;

// Load environment variables
$dotenv = new Dotenv();
$dotenv->load(__DIR__ . '/../.env');

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
    $conn = DriverManager::getConnection($connectionParams);
    
    echo "Connected to database: {$connectionParams['dbname']}\n\n";
    
    // Migration 1: Add CY allocation fields to containers table
    echo "Running Migration 1: Add CY allocation fields to containers table...\n";
    
    $conn->executeStatement('ALTER TABLE containers ADD COLUMN cy_allocation_id INT NULL');
    echo "  ✓ Added cy_allocation_id column\n";
    
    $conn->executeStatement("ALTER TABLE containers ADD COLUMN allocation_status VARCHAR(20) DEFAULT 'pre_forecast' NOT NULL");
    echo "  ✓ Added allocation_status column\n";
    
    $conn->executeStatement('ALTER TABLE containers ADD COLUMN allocated_at DATETIME NULL');
    echo "  ✓ Added allocated_at column\n";
    
    $conn->executeStatement('ALTER TABLE containers ADD COLUMN allocation_locked_at DATETIME NULL');
    echo "  ✓ Added allocation_locked_at column\n";
    
    $conn->executeStatement('ALTER TABLE containers ADD CONSTRAINT FK_CONTAINER_CY_ALLOCATION 
        FOREIGN KEY (cy_allocation_id) 
        REFERENCES shipping_line_terminal_allocations(id) 
        ON DELETE SET NULL');
    echo "  ✓ Created foreign key constraint\n";
    
    $conn->executeStatement('CREATE INDEX IDX_CONTAINERS_CY_ALLOCATION ON containers(cy_allocation_id)');
    echo "  ✓ Created index on cy_allocation_id\n";
    
    $conn->executeStatement('CREATE INDEX IDX_CONTAINERS_ALLOCATION_STATUS ON containers(allocation_status)');
    echo "  ✓ Created index on allocation_status\n";
    
    echo "Migration 1 completed successfully!\n\n";
    
    // Migration 2: Create container_allocation_audit table
    echo "Running Migration 2: Create container_allocation_audit table...\n";
    
    $conn->executeStatement('CREATE TABLE container_allocation_audit (
        id INT AUTO_INCREMENT PRIMARY KEY,
        container_id INT NOT NULL,
        previous_allocation_id INT NULL,
        new_allocation_id INT NOT NULL,
        changed_by_id INT NOT NULL,
        changed_at DATETIME NOT NULL,
        change_type VARCHAR(50) NOT NULL,
        reason TEXT NULL,
        metadata JSON NULL,
        INDEX IDX_AUDIT_CONTAINER (container_id),
        INDEX IDX_AUDIT_CHANGED_AT (changed_at),
        INDEX IDX_AUDIT_CHANGE_TYPE (change_type),
        CONSTRAINT FK_AUDIT_CONTAINER 
            FOREIGN KEY (container_id) 
            REFERENCES containers(id) 
            ON DELETE CASCADE,
        CONSTRAINT FK_AUDIT_PREVIOUS_ALLOCATION 
            FOREIGN KEY (previous_allocation_id) 
            REFERENCES shipping_line_terminal_allocations(id) 
            ON DELETE SET NULL,
        CONSTRAINT FK_AUDIT_NEW_ALLOCATION 
            FOREIGN KEY (new_allocation_id) 
            REFERENCES shipping_line_terminal_allocations(id) 
            ON DELETE CASCADE,
        CONSTRAINT FK_AUDIT_CHANGED_BY 
            FOREIGN KEY (changed_by_id) 
            REFERENCES users(id) 
            ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    
    echo "  ✓ Created container_allocation_audit table\n";
    echo "  ✓ Created indexes and foreign key constraints\n";
    
    echo "Migration 2 completed successfully!\n\n";
    
    // Verify migrations
    echo "Verifying migrations...\n";
    
    $result = $conn->fetchAssociative("SHOW COLUMNS FROM containers LIKE 'cy_allocation_id'");
    if ($result) {
        echo "  ✓ containers.cy_allocation_id exists\n";
    }
    
    $result = $conn->fetchAssociative("SHOW COLUMNS FROM containers LIKE 'allocation_status'");
    if ($result) {
        echo "  ✓ containers.allocation_status exists\n";
    }
    
    $result = $conn->fetchAssociative("SHOW TABLES LIKE 'container_allocation_audit'");
    if ($result) {
        echo "  ✓ container_allocation_audit table exists\n";
    }
    
    echo "\n✅ All migrations completed successfully!\n";
    echo "\nNext steps:\n";
    echo "1. Verify entity relationships work correctly\n";
    echo "2. Proceed to Task 2: Checkpoint - Verify database schema\n";
    
} catch (\Exception $e) {
    echo "\n❌ Migration failed: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
