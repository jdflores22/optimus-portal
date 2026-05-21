<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Combine status and active columns into a single status column
 */
final class Version20260120140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Combine status and active columns into a single status column with expanded enum values';
    }

    public function up(Schema $schema): void
    {
        // First, update existing data to new status values
        // PUBLISHED + active=1 -> ACTIVE
        // PUBLISHED + active=0 -> INACTIVE  
        // DRAFT + active=0 -> DRAFT
        
        $this->addSql("UPDATE form_configurations SET status = 'ACTIVE' WHERE status = 'PUBLISHED' AND active = 1");
        $this->addSql("UPDATE form_configurations SET status = 'INACTIVE' WHERE status = 'PUBLISHED' AND active = 0");
        // DRAFT forms remain as DRAFT
        
        // Drop the active column
        $this->addSql('ALTER TABLE form_configurations DROP COLUMN active');
    }

    public function down(Schema $schema): void
    {
        // Add back the active column
        $this->addSql('ALTER TABLE form_configurations ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 0');
        
        // Restore the old data structure
        $this->addSql("UPDATE form_configurations SET status = 'PUBLISHED', active = 1 WHERE status = 'ACTIVE'");
        $this->addSql("UPDATE form_configurations SET status = 'PUBLISHED', active = 0 WHERE status = 'INACTIVE'");
        // DRAFT forms remain as DRAFT with active = 0
    }
}