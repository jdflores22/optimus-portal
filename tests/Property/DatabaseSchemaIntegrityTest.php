<?php

namespace App\Tests\Property;

use PHPUnit\Framework\TestCase;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Property-Based Test for Database Schema Integrity
 * Task 1.4: Write property test for database schema integrity
 * Property 21: Post-Migration Integrity Validation
 * Validates: Requirements 7.4
 */
class DatabaseSchemaIntegrityTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get('doctrine.dbal.default_connection');
    }

    /**
     * Feature: dynamic-shipping-line-management, Property 21: Post-Migration Integrity Validation
     * 
     * For any database state after migration completion, all integrity constraints should be satisfied 
     * and no data corruption should exist.
     */
    public function testPostMigrationIntegrityValidation(): void
    {
        // Test 1: Verify all required tables exist
        $requiredTables = ['shipping_lines', 'activity_logs', 'users'];
        foreach ($requiredTables as $table) {
            $result = $this->connection->fetchOne(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?",
                [$table]
            );
            $this->assertEquals(1, $result, "Required table '{$table}' does not exist");
        }

        // Test 2: Verify shipping_lines table structure
        $shippingLinesColumns = $this->getTableColumns('shipping_lines');
        $requiredShippingLinesColumns = ['id', 'brand_name', 'portal_config', 'created_at', 'updated_at', 'is_active'];
        foreach ($requiredShippingLinesColumns as $column) {
            $this->assertContains($column, $shippingLinesColumns, "Required column '{$column}' missing from shipping_lines table");
        }

        // Test 3: Verify activity_logs table structure
        $activityLogsColumns = $this->getTableColumns('activity_logs');
        $requiredActivityLogsColumns = [
            'id', 'user_id', 'shipping_line_id', 'activity_type', 'entity_type', 'entity_id',
            'old_values', 'new_values', 'ip_address', 'user_agent', 'session_id', 
            'additional_context', 'created_at'
        ];
        foreach ($requiredActivityLogsColumns as $column) {
            $this->assertContains($column, $activityLogsColumns, "Required column '{$column}' missing from activity_logs table");
        }

        // Test 4: Verify users table has hierarchy columns
        $usersColumns = $this->getTableColumns('users');
        $requiredHierarchyColumns = ['shipping_line_admin_id', 'managed_shipping_line_id'];
        foreach ($requiredHierarchyColumns as $column) {
            $this->assertContains($column, $usersColumns, "Required hierarchy column '{$column}' missing from users table");
        }

        // Test 5: Verify foreign key constraints exist
        $this->assertForeignKeyExists('activity_logs', 'user_id', 'users', 'id');
        $this->assertForeignKeyExists('activity_logs', 'shipping_line_id', 'shipping_lines', 'id');
        $this->assertForeignKeyExists('users', 'shipping_line_admin_id', 'users', 'id');
        $this->assertForeignKeyExists('users', 'managed_shipping_line_id', 'shipping_lines', 'id');

        // Test 6: Verify unique constraints
        $this->assertUniqueConstraintExists('shipping_lines', 'brand_name');

        // Test 7: Verify indexes exist for performance
        $this->assertIndexExists('shipping_lines', 'idx_shipping_lines_brand_name');
        $this->assertIndexExists('shipping_lines', 'idx_shipping_lines_active');
        $this->assertIndexExists('activity_logs', 'idx_activity_logs_user_activity');
        $this->assertIndexExists('users', 'idx_users_shipping_line_admin');
        $this->assertIndexExists('users', 'idx_users_managed_shipping_line');

        // Test 8: Verify rollback infrastructure exists
        $rollbackTables = ['shipping_lines_backup', 'activity_logs_backup', 'users_hierarchy_backup', 'migration_rollback_status'];
        foreach ($rollbackTables as $table) {
            $result = $this->connection->fetchOne(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?",
                [$table]
            );
            $this->assertEquals(1, $result, "Rollback table '{$table}' does not exist");
        }
    }

    private function getTableColumns(string $tableName): array
    {
        $columns = $this->connection->fetchAllAssociative(
            "SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?",
            [$tableName]
        );
        return array_column($columns, 'column_name');
    }

    private function assertForeignKeyExists(string $table, string $column, string $referencedTable, string $referencedColumn): void
    {
        $result = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.key_column_usage 
             WHERE table_schema = DATABASE() 
             AND table_name = ? 
             AND column_name = ? 
             AND referenced_table_name = ? 
             AND referenced_column_name = ?",
            [$table, $column, $referencedTable, $referencedColumn]
        );
        $this->assertGreaterThan(0, $result, "Foreign key constraint missing: {$table}.{$column} -> {$referencedTable}.{$referencedColumn}");
    }

    private function assertUniqueConstraintExists(string $table, string $column): void
    {
        $result = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.statistics 
             WHERE table_schema = DATABASE() 
             AND table_name = ? 
             AND column_name = ? 
             AND non_unique = 0",
            [$table, $column]
        );
        $this->assertGreaterThan(0, $result, "Unique constraint missing on {$table}.{$column}");
    }

    private function assertIndexExists(string $table, string $indexName): void
    {
        $result = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.statistics 
             WHERE table_schema = DATABASE() 
             AND table_name = ? 
             AND index_name = ?",
            [$table, $indexName]
        );
        $this->assertGreaterThan(0, $result, "Index '{$indexName}' missing on table '{$table}'");
    }
}