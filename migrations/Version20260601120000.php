<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add version tracking fields to billings table
 * Adds version and previous_billing_id fields to support payment history for detention billings
 */
final class Version20260601120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add version tracking fields (version, previous_billing_id) to billings table';
    }

    public function up(Schema $schema): void
    {
        // Add version column with default value of 1
        $this->addSql('ALTER TABLE billings ADD version INT NOT NULL DEFAULT 1');
        
        // Add previous_billing_id column (nullable, self-referencing foreign key)
        $this->addSql('ALTER TABLE billings ADD previous_billing_id INT NULL');
        
        // Add foreign key constraint
        $this->addSql('ALTER TABLE billings ADD CONSTRAINT FK_billings_previous 
                       FOREIGN KEY (previous_billing_id) REFERENCES billings (id) ON DELETE SET NULL');
        
        // Add indexes for performance
        $this->addSql('CREATE INDEX idx_billings_version ON billings (version)');
        $this->addSql('CREATE INDEX idx_billings_previous ON billings (previous_billing_id)');
    }

    public function down(Schema $schema): void
    {
        // Drop indexes
        $this->addSql('DROP INDEX idx_billings_previous ON billings');
        $this->addSql('DROP INDEX idx_billings_version ON billings');
        
        // Drop foreign key
        $this->addSql('ALTER TABLE billings DROP FOREIGN KEY FK_billings_previous');
        
        // Drop columns
        $this->addSql('ALTER TABLE billings DROP previous_billing_id');
        $this->addSql('ALTER TABLE billings DROP version');
    }
}
