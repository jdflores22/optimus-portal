<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration to extend billings table for detention charges
 * Task 1.2: Create billings table extension migration
 * Requirements: 8.1, 8.2, 8.3, 8.4
 */
final class Version20260428120001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Extend billings table to support detention charges for expired eDO renewals';
    }

    public function up(Schema $schema): void
    {
        // Add billing_type column with default value 'manifest'
        $this->addSql("
            ALTER TABLE billings
            ADD COLUMN billing_type VARCHAR(50) NOT NULL DEFAULT 'manifest' AFTER manifest_id
        ");
        
        // Add edo_renewal_request_id column
        $this->addSql('
            ALTER TABLE billings
            ADD COLUMN edo_renewal_request_id INT NULL AFTER billing_type
        ');
        
        // Add detention_days column
        $this->addSql('
            ALTER TABLE billings
            ADD COLUMN detention_days INT NULL AFTER edo_renewal_request_id
        ');
        
        // Add detention_rate column
        $this->addSql('
            ALTER TABLE billings
            ADD COLUMN detention_rate DECIMAL(10, 2) NULL AFTER detention_days
        ');
        
        // Add index for billing_type
        $this->addSql('
            CREATE INDEX idx_billings_type ON billings(billing_type)
        ');
        
        // Add index for edo_renewal_request_id
        $this->addSql('
            CREATE INDEX idx_billings_renewal_request ON billings(edo_renewal_request_id)
        ');
        
        // Add foreign key constraint to edo_renewal_requests table
        $this->addSql('
            ALTER TABLE billings
            ADD CONSTRAINT fk_billings_renewal_request 
                FOREIGN KEY (edo_renewal_request_id) 
                REFERENCES edo_renewal_requests(id) 
                ON DELETE SET NULL
        ');
    }

    public function down(Schema $schema): void
    {
        // Drop foreign key constraint
        $this->addSql('
            ALTER TABLE billings
            DROP FOREIGN KEY fk_billings_renewal_request
        ');
        
        // Drop indexes
        $this->addSql('DROP INDEX idx_billings_renewal_request ON billings');
        $this->addSql('DROP INDEX idx_billings_type ON billings');
        
        // Drop columns
        $this->addSql('ALTER TABLE billings DROP COLUMN detention_rate');
        $this->addSql('ALTER TABLE billings DROP COLUMN detention_days');
        $this->addSql('ALTER TABLE billings DROP COLUMN edo_renewal_request_id');
        $this->addSql('ALTER TABLE billings DROP COLUMN billing_type');
    }
}
