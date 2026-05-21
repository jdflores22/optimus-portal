<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add manifest_file_path column to manifests table
 */
final class Version20260405120008 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add manifest_file_path column to manifests table for storing uploaded manifest files';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE manifests ADD manifest_file_path VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE manifests DROP manifest_file_path');
    }
}
