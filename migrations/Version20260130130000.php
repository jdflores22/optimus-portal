<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration for Dynamic Shipping Line Management - Task 1.2
 * Create comprehensive activity_logs table for ALL system activities
 * Include user_id, shipping_line_id, activity_type, entity_type, entity_id, old_values, new_values, 
 * ip_address, user_agent, session_id, additional_context, created_at
 * Add foreign key constraints and performance indexes
 * Requirements: 12.1, 12.2, 12.3
 */
final class Version20260130130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create comprehensive activity_logs table for ALL system activities';
    }

    public function up(Schema $schema): void
    {
        // Create activity_logs table for comprehensive system activity logging
        $this->addSql('CREATE TABLE activity_logs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            shipping_line_id INT NULL,
            activity_type VARCHAR(50) NOT NULL,
            entity_type VARCHAR(100) NULL,
            entity_id INT NULL,
            old_values JSON NULL,
            new_values JSON NULL,
            ip_address VARCHAR(45) NOT NULL,
            user_agent TEXT NULL,
            session_id VARCHAR(255) NULL,
            additional_context JSON NULL,
            created_at DATETIME NOT NULL,
            
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (shipping_line_id) REFERENCES shipping_lines(id) ON DELETE SET NULL,
            
            INDEX idx_activity_logs_user_activity (user_id, created_at),
            INDEX idx_activity_logs_shipping_line_activity (shipping_line_id, created_at),
            INDEX idx_activity_logs_activity_type (activity_type, created_at),
            INDEX idx_activity_logs_entity_activity (entity_type, entity_id, created_at),
            INDEX idx_activity_logs_created_at (created_at),
            INDEX idx_activity_logs_session (session_id),
            INDEX idx_activity_logs_ip_address (ip_address)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        // Drop activity_logs table
        $this->addSql('DROP TABLE activity_logs');
    }
}