<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Multi-Tenant Shipping Line - Task 1.1: Enhance ShippingLine Entity
 * 
 * Adds logo_path and brand_color columns to shipping_lines table
 * to support shipping line branding in the multi-tenant system.
 */
final class Version20260412130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add logo_path and brand_color columns to shipping_lines table with indexes';
    }

    public function up(Schema $schema): void
    {
        // Add logo_path column (VARCHAR 500, NULLABLE)
        $this->addSql('ALTER TABLE shipping_lines ADD COLUMN logo_path VARCHAR(500) NULL COMMENT \'Path to shipping line logo file\'');
        
        // Add brand_color column (VARCHAR 7, NULLABLE) for hex color codes
        $this->addSql('ALTER TABLE shipping_lines ADD COLUMN brand_color VARCHAR(7) NULL COMMENT \'Hex color code for branding (e.g., #0066CC)\'');
        
        // Add indexes for performance
        $this->addSql('CREATE INDEX idx_shipping_lines_logo ON shipping_lines (logo_path)');
        $this->addSql('CREATE INDEX idx_shipping_lines_brand_color ON shipping_lines (brand_color)');
    }

    public function down(Schema $schema): void
    {
        // Drop indexes first
        $this->addSql('DROP INDEX idx_shipping_lines_brand_color ON shipping_lines');
        $this->addSql('DROP INDEX idx_shipping_lines_logo ON shipping_lines');
        
        // Drop columns
        $this->addSql('ALTER TABLE shipping_lines DROP COLUMN brand_color');
        $this->addSql('ALTER TABLE shipping_lines DROP COLUMN logo_path');
    }
}
