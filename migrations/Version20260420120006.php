<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration to add NOA reference to Manifest table
 */
final class Version20260420120006 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add noa_id foreign key to manifests table for container-based eDO workflow';
    }

    public function up(Schema $schema): void
    {
        // Add noa_id column to manifests table
        $this->addSql('ALTER TABLE manifests ADD COLUMN noa_id INT DEFAULT NULL');
        
        // Add foreign key constraint
        $this->addSql('ALTER TABLE manifests ADD CONSTRAINT FK_manifests_noa 
            FOREIGN KEY (noa_id) REFERENCES noa(id) ON DELETE SET NULL');
        
        // Add index for performance
        $this->addSql('CREATE INDEX idx_manifests_noa ON manifests(noa_id)');
    }

    public function down(Schema $schema): void
    {
        // Drop foreign key constraint
        $this->addSql('ALTER TABLE manifests DROP FOREIGN KEY FK_manifests_noa');
        
        // Drop index
        $this->addSql('DROP INDEX idx_manifests_noa ON manifests');
        
        // Drop column
        $this->addSql('ALTER TABLE manifests DROP COLUMN noa_id');
    }
}
