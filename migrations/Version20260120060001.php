<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260120060001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add active column to form_configurations table';
    }

    public function up(Schema $schema): void
    {
        // Add active column to form_configurations table
        $this->addSql('ALTER TABLE form_configurations ADD active TINYINT(1) NOT NULL DEFAULT 0');
        
        // Set published forms as active by default
        $this->addSql('UPDATE form_configurations SET active = 1 WHERE status = \'PUBLISHED\'');
    }

    public function down(Schema $schema): void
    {
        // Remove active column from form_configurations table
        $this->addSql('ALTER TABLE form_configurations DROP active');
    }
}
