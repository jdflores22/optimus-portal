<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration for Dynamic Shipping Line Management - Task 1.1
 * Create shipping_lines table with brand_name, portal_config, timestamps, and is_active fields
 * Add unique constraint on brand_name and appropriate indexes
 * Requirements: 1.2, 1.4, 1.6
 */
final class Version20260130120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create shipping_lines table for dynamic shipping line management';
    }

    public function up(Schema $schema): void
    {
        // Create shipping_lines table
        $this->addSql('CREATE TABLE shipping_lines (
            id INT AUTO_INCREMENT PRIMARY KEY,
            brand_name VARCHAR(255) NOT NULL,
            portal_config JSON,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            is_active BOOLEAN DEFAULT TRUE NOT NULL,
            UNIQUE INDEX UNIQ_shipping_lines_brand_name (brand_name),
            INDEX idx_shipping_lines_brand_name (brand_name),
            INDEX idx_shipping_lines_active (is_active),
            INDEX idx_shipping_lines_created_at (created_at)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        // Drop shipping_lines table
        $this->addSql('DROP TABLE shipping_lines');
    }
}