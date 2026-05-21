<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration for Dynamic Shipping Line Management - Task 1.3
 * Add shipping_line_admin_id and managed_shipping_line_id columns to users table
 * Create foreign key constraints with proper cascade rules
 * Add indexes for efficient hierarchy queries
 * Requirements: 3.1, 3.2, 3.5
 */
final class Version20260130140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Modify users table for hierarchy support with shipping line relationships';
    }

    public function up(Schema $schema): void
    {
        // Add hierarchy support columns to users table
        $this->addSql('ALTER TABLE users 
            ADD COLUMN shipping_line_admin_id INT NULL,
            ADD COLUMN managed_shipping_line_id INT NULL');
        
        // Add foreign key constraints with proper cascade rules
        $this->addSql('ALTER TABLE users 
            ADD CONSTRAINT fk_users_shipping_line_admin 
                FOREIGN KEY (shipping_line_admin_id) REFERENCES users(id) ON DELETE SET NULL,
            ADD CONSTRAINT fk_users_managed_shipping_line 
                FOREIGN KEY (managed_shipping_line_id) REFERENCES shipping_lines(id) ON DELETE SET NULL');
        
        // Add indexes for efficient hierarchy queries
        $this->addSql('CREATE INDEX idx_users_shipping_line_admin ON users (shipping_line_admin_id)');
        $this->addSql('CREATE INDEX idx_users_managed_shipping_line ON users (managed_shipping_line_id)');
        $this->addSql('CREATE INDEX idx_users_hierarchy_lookup ON users (role, shipping_line_admin_id, managed_shipping_line_id)');
    }

    public function down(Schema $schema): void
    {
        // Remove indexes first
        $this->addSql('DROP INDEX idx_users_shipping_line_admin ON users');
        $this->addSql('DROP INDEX idx_users_managed_shipping_line ON users');
        $this->addSql('DROP INDEX idx_users_hierarchy_lookup ON users');
        
        // Remove foreign key constraints
        $this->addSql('ALTER TABLE users DROP FOREIGN KEY fk_users_shipping_line_admin');
        $this->addSql('ALTER TABLE users DROP FOREIGN KEY fk_users_managed_shipping_line');
        
        // Remove columns
        $this->addSql('ALTER TABLE users 
            DROP COLUMN shipping_line_admin_id,
            DROP COLUMN managed_shipping_line_id');
    }
}