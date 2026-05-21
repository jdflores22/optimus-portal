<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add profilePhoto column to users table
 */
final class Version20260120150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add profilePhoto column to users table';
    }

    public function up(Schema $schema): void
    {
        // Check if column already exists before adding
        $this->addSql('ALTER TABLE users ADD COLUMN profile_photo VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP COLUMN profile_photo');
    }
}