<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add active column to form_configurations table
 */
final class Version20260120120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add active column to form_configurations table for better form lifecycle management';
    }

    public function up(Schema $schema): void
    {
        // Add active column with default value false
        $this->addSql('ALTER TABLE form_configurations ADD active TINYINT(1) DEFAULT 0 NOT NULL');
        
        // Set currently published forms as active (only one per type should be active)
        $this->addSql('UPDATE form_configurations SET active = 1 WHERE status = "PUBLISHED"');
    }

    public function down(Schema $schema): void
    {
        // Remove active column
        $this->addSql('ALTER TABLE form_configurations DROP active');
    }
}