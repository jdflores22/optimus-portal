<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration to support multiple eDOs per manifest (one per container)
 * Changes Manifest->eDO from OneToOne to OneToMany relationship
 */
final class Version20260420120015 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Update eDO-Manifest relationship to support multiple eDOs per manifest (one per container)';
    }

    public function up(Schema $schema): void
    {
        // Remove the unique constraint on manifest_id in electronic_delivery_orders table
        // This allows multiple eDOs to reference the same manifest
        $this->addSql('ALTER TABLE electronic_delivery_orders DROP INDEX UNIQ_A1B2C3D4E5F6G7H8');
        
        // Update the foreign key constraint to allow multiple eDOs per manifest
        $this->addSql('ALTER TABLE electronic_delivery_orders MODIFY COLUMN manifest_id INT NOT NULL');
        
        // Add index for better performance on manifest_id queries
        $this->addSql('CREATE INDEX IDX_EDO_MANIFEST_ID ON electronic_delivery_orders (manifest_id)');
    }

    public function down(Schema $schema): void
    {
        // Remove the index
        $this->addSql('DROP INDEX IDX_EDO_MANIFEST_ID ON electronic_delivery_orders');
        
        // Add back the unique constraint (this will fail if there are multiple eDOs per manifest)
        $this->addSql('ALTER TABLE electronic_delivery_orders ADD CONSTRAINT UNIQ_A1B2C3D4E5F6G7H8 UNIQUE (manifest_id)');
    }
}