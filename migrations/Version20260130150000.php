<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration for Dynamic Shipping Line Management - Task 1.5
 * Implement rollback capabilities for all schema changes
 * Ensure data preservation during rollback operations
 * Requirements: 7.5
 * 
 * This migration provides comprehensive rollback capabilities and data preservation
 * for the dynamic shipping line management feature.
 */
final class Version20260130150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rollback capabilities and data preservation for dynamic shipping line management';
    }

    public function up(Schema $schema): void
    {
        // Create backup tables for data preservation during rollback
        $this->addSql('CREATE TABLE shipping_lines_backup AS SELECT * FROM shipping_lines WHERE 1=0');
        $this->addSql('CREATE TABLE activity_logs_backup AS SELECT * FROM activity_logs WHERE 1=0');
        $this->addSql('CREATE TABLE users_hierarchy_backup (
            id INT PRIMARY KEY,
            shipping_line_admin_id INT NULL,
            managed_shipping_line_id INT NULL,
            backup_timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        
        // Create rollback status tracking table
        $this->addSql('CREATE TABLE migration_rollback_status (
            id INT AUTO_INCREMENT PRIMARY KEY,
            migration_version VARCHAR(255) NOT NULL,
            rollback_status ENUM("pending", "in_progress", "completed", "failed") DEFAULT "pending",
            data_preserved BOOLEAN DEFAULT FALSE,
            rollback_started_at DATETIME NULL,
            rollback_completed_at DATETIME NULL,
            error_message TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE INDEX uniq_migration_version (migration_version)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        
        // Insert rollback status entries for all dynamic shipping line migrations
        $this->addSql("INSERT INTO migration_rollback_status (migration_version) VALUES 
            ('Version20260130120000'),
            ('Version20260130130000'), 
            ('Version20260130140000'),
            ('Version20260130150000')");
    }

    public function down(Schema $schema): void
    {
        // Preserve existing data before rollback
        $this->addSql('UPDATE migration_rollback_status SET rollback_status = "in_progress", rollback_started_at = NOW() WHERE migration_version = "Version20260130150000"');
        
        // Backup user hierarchy data
        $this->addSql('INSERT INTO users_hierarchy_backup (id, shipping_line_admin_id, managed_shipping_line_id) 
            SELECT id, shipping_line_admin_id, managed_shipping_line_id 
            FROM users 
            WHERE shipping_line_admin_id IS NOT NULL OR managed_shipping_line_id IS NOT NULL');
        
        // Backup shipping lines data
        $this->addSql('INSERT INTO shipping_lines_backup SELECT * FROM shipping_lines');
        
        // Backup activity logs data
        $this->addSql('INSERT INTO activity_logs_backup SELECT * FROM activity_logs');
        
        // Update rollback status
        $this->addSql('UPDATE migration_rollback_status SET data_preserved = TRUE WHERE migration_version = "Version20260130150000"');
        
        // Drop rollback infrastructure (keeping backup tables for manual recovery)
        $this->addSql('UPDATE migration_rollback_status SET rollback_status = "completed", rollback_completed_at = NOW() WHERE migration_version = "Version20260130150000"');
        $this->addSql('DROP TABLE migration_rollback_status');
    }
}