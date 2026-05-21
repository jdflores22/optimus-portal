<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add teu_value column to container_sizes table
 */
final class Version20260420120010 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add teu_value column to container_sizes table';
    }

    public function up(Schema $schema): void
    {
        // Add teu_value column if it doesn't exist
        $this->addSql('ALTER TABLE container_sizes ADD COLUMN IF NOT EXISTS teu_value FLOAT NOT NULL DEFAULT 1.0 AFTER description');
    }

    public function down(Schema $schema): void
    {
        // Remove teu_value column
        $this->addSql('ALTER TABLE container_sizes DROP COLUMN IF EXISTS teu_value');
    }
}
