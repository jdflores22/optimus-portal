<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Container-Based eDO Workflow - Task 1.2: Extend Container entity for NOA and eDO relationships
 */
final class Version20260420120001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add NOA and Manifest relationships to containers table';
    }

    public function up(Schema $schema): void
    {
        // Add NOA relationship
        $this->addSql('ALTER TABLE containers 
            ADD COLUMN noa_id INT DEFAULT NULL AFTER shipping_line_id');

        // Add Manifest relationship
        $this->addSql('ALTER TABLE containers 
            ADD COLUMN manifest_id INT DEFAULT NULL AFTER noa_id');

        // Add foreign keys
        $this->addSql('ALTER TABLE containers 
            ADD CONSTRAINT FK_containers_noa 
            FOREIGN KEY (noa_id) REFERENCES noa (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE containers 
            ADD CONSTRAINT FK_containers_manifest 
            FOREIGN KEY (manifest_id) REFERENCES manifests (id) ON DELETE SET NULL');

        // Add indexes
        $this->addSql('CREATE INDEX IDX_containers_noa ON containers (noa_id)');
        $this->addSql('CREATE INDEX IDX_containers_manifest ON containers (manifest_id)');
    }

    public function down(Schema $schema): void
    {
        // Drop indexes
        $this->addSql('DROP INDEX IDX_containers_manifest ON containers');
        $this->addSql('DROP INDEX IDX_containers_noa ON containers');

        // Drop foreign keys
        $this->addSql('ALTER TABLE containers DROP FOREIGN KEY FK_containers_noa');
        $this->addSql('ALTER TABLE containers DROP FOREIGN KEY FK_containers_manifest');

        // Drop columns
        $this->addSql('ALTER TABLE containers DROP COLUMN manifest_id');
        $this->addSql('ALTER TABLE containers DROP COLUMN noa_id');
    }
}
