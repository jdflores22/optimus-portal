<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Multi-Tenant Shipping Line - Task 1.3: Update Accreditation for Per-Shipping-Line
 * 
 * Adds shipping_line_id to accreditation_submissions table and updates
 * unique constraints to support per-shipping-line accreditation.
 */
final class Version20260412150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add shipping_line_id to accreditation_submissions with composite unique constraint';
    }

    public function up(Schema $schema): void
    {
        // Add shipping_line_id column (NULLABLE initially for data migration)
        $this->addSql('ALTER TABLE accreditation_submissions ADD COLUMN shipping_line_id INT NULL COMMENT \'Foreign key to shipping_lines table\'');
        
        // Add foreign key constraint
        $this->addSql('ALTER TABLE accreditation_submissions ADD CONSTRAINT fk_accreditation_shipping_line FOREIGN KEY (shipping_line_id) REFERENCES shipping_lines(id) ON DELETE RESTRICT');
        
        // Add index for shipping_line_id
        $this->addSql('CREATE INDEX idx_accreditation_shipping_line ON accreditation_submissions (shipping_line_id)');
        
        // Drop old unique constraint on applicant_id if it exists
        // Note: Check if constraint exists first to avoid errors
        $this->addSql('ALTER TABLE accreditation_submissions DROP INDEX IF EXISTS UNIQ_applicant_id');
        
        // Add composite unique constraint (applicant_id, shipping_line_id)
        // This allows one user to have multiple accreditations (one per shipping line)
        $this->addSql('ALTER TABLE accreditation_submissions ADD UNIQUE KEY unique_applicant_shipping_line (applicant_id, shipping_line_id)');
    }

    public function down(Schema $schema): void
    {
        // Drop composite unique constraint
        $this->addSql('ALTER TABLE accreditation_submissions DROP INDEX unique_applicant_shipping_line');
        
        // Restore old unique constraint on applicant_id (if needed)
        // Note: This may fail if there are multiple accreditations per user
        // $this->addSql('ALTER TABLE accreditation_submissions ADD UNIQUE KEY UNIQ_applicant_id (applicant_id)');
        
        // Drop index
        $this->addSql('DROP INDEX idx_accreditation_shipping_line ON accreditation_submissions');
        
        // Drop foreign key
        $this->addSql('ALTER TABLE accreditation_submissions DROP FOREIGN KEY fk_accreditation_shipping_line');
        
        // Drop column
        $this->addSql('ALTER TABLE accreditation_submissions DROP COLUMN shipping_line_id');
    }
}
