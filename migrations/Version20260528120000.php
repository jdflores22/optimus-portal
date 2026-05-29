<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add version control fields to payments table
 * 
 * This migration adds:
 * - version column (INT, NOT NULL, DEFAULT 1)
 * - previous_payment_id column (INT, NULL)
 * - Foreign key constraint for previous_payment_id
 * - Index on version column
 * - Index on previous_payment_id column
 * - Composite index on (manifest_id, payment_type, version)
 */
final class Version20260528120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add version control fields to payments table for explicit payment version tracking';
    }

    public function up(Schema $schema): void
    {
        // Add version column (INT, NOT NULL, DEFAULT 1)
        $this->addSql('ALTER TABLE payments ADD version INT NOT NULL DEFAULT 1');
        
        // Add previous_payment_id column (INT, NULL)
        $this->addSql('ALTER TABLE payments ADD previous_payment_id INT NULL');
        
        // Add foreign key constraint for previous_payment_id
        $this->addSql('
            ALTER TABLE payments 
            ADD CONSTRAINT FK_payments_previous_payment 
            FOREIGN KEY (previous_payment_id) 
            REFERENCES payments (id) 
            ON DELETE SET NULL
        ');
        
        // Add index on version column
        $this->addSql('CREATE INDEX idx_payments_version ON payments (version)');
        
        // Add index on previous_payment_id column
        $this->addSql('CREATE INDEX idx_payments_previous_payment ON payments (previous_payment_id)');
        
        // Add composite index on (manifest_id, payment_type, version)
        $this->addSql('CREATE INDEX idx_payments_manifest_type_version ON payments (manifest_id, payment_type, version)');
    }

    public function down(Schema $schema): void
    {
        // Drop composite index
        $this->addSql('DROP INDEX idx_payments_manifest_type_version ON payments');
        
        // Drop index on previous_payment_id
        $this->addSql('DROP INDEX idx_payments_previous_payment ON payments');
        
        // Drop index on version
        $this->addSql('DROP INDEX idx_payments_version ON payments');
        
        // Drop foreign key constraint
        $this->addSql('ALTER TABLE payments DROP FOREIGN KEY FK_payments_previous_payment');
        
        // Drop previous_payment_id column
        $this->addSql('ALTER TABLE payments DROP previous_payment_id');
        
        // Drop version column
        $this->addSql('ALTER TABLE payments DROP version');
    }
}
