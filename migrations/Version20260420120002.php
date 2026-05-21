<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Container-Based eDO Workflow - Task 1.3: Modify ElectronicDeliveryOrder entity for container-level management
 */
final class Version20260420120002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add container relationship and new fields to electronic_delivery_orders table, update EDOStatus enum';
    }

    public function up(Schema $schema): void
    {
        // Add container relationship
        $this->addSql('ALTER TABLE electronic_delivery_orders 
            ADD COLUMN container_id INT NOT NULL AFTER edo_number');

        // Add new fields for expiration and versioning
        $this->addSql('ALTER TABLE electronic_delivery_orders 
            ADD COLUMN expires_at DATETIME DEFAULT NULL AFTER released_at');

        $this->addSql('ALTER TABLE electronic_delivery_orders 
            ADD COLUMN expired_days INT DEFAULT NULL AFTER expires_at');

        $this->addSql('ALTER TABLE electronic_delivery_orders 
            ADD COLUMN version INT NOT NULL DEFAULT 1 AFTER expired_days');

        $this->addSql('ALTER TABLE electronic_delivery_orders 
            ADD COLUMN previous_version_id INT DEFAULT NULL AFTER version');

        // Update status enum to include new values
        $this->addSql("ALTER TABLE electronic_delivery_orders 
            MODIFY COLUMN status VARCHAR(20) NOT NULL");

        // Add foreign keys
        $this->addSql('ALTER TABLE electronic_delivery_orders 
            ADD CONSTRAINT FK_edo_container 
            FOREIGN KEY (container_id) REFERENCES containers (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE electronic_delivery_orders 
            ADD CONSTRAINT FK_edo_previous_version 
            FOREIGN KEY (previous_version_id) REFERENCES electronic_delivery_orders (id) ON DELETE SET NULL');

        // Add indexes
        $this->addSql('CREATE INDEX IDX_edos_container ON electronic_delivery_orders (container_id)');
        $this->addSql('CREATE INDEX IDX_edos_expires_at ON electronic_delivery_orders (expires_at)');
        $this->addSql('CREATE INDEX IDX_edos_version ON electronic_delivery_orders (version)');
        $this->addSql('CREATE INDEX IDX_edos_previous_version ON electronic_delivery_orders (previous_version_id)');
    }

    public function down(Schema $schema): void
    {
        // Drop indexes
        $this->addSql('DROP INDEX IDX_edos_previous_version ON electronic_delivery_orders');
        $this->addSql('DROP INDEX IDX_edos_version ON electronic_delivery_orders');
        $this->addSql('DROP INDEX IDX_edos_expires_at ON electronic_delivery_orders');
        $this->addSql('DROP INDEX IDX_edos_container ON electronic_delivery_orders');

        // Drop foreign keys
        $this->addSql('ALTER TABLE electronic_delivery_orders DROP FOREIGN KEY FK_edo_previous_version');
        $this->addSql('ALTER TABLE electronic_delivery_orders DROP FOREIGN KEY FK_edo_container');

        // Drop columns
        $this->addSql('ALTER TABLE electronic_delivery_orders DROP COLUMN previous_version_id');
        $this->addSql('ALTER TABLE electronic_delivery_orders DROP COLUMN version');
        $this->addSql('ALTER TABLE electronic_delivery_orders DROP COLUMN expired_days');
        $this->addSql('ALTER TABLE electronic_delivery_orders DROP COLUMN expires_at');
        $this->addSql('ALTER TABLE electronic_delivery_orders DROP COLUMN container_id');
    }
}
