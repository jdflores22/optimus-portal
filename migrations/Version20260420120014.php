<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration for eDO Per-Container Payment feature
 * - Add edo_id column to payments_edo table with foreign key to electronic_delivery_orders
 * - Add official_receipt_path column to payments_edo table
 * - Add fee_amount column to electronic_delivery_orders table
 * - Add version column to electronic_delivery_orders table for optimistic locking
 * - Create indexes for performance optimization
 */
final class Version20260420120014 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add per-container payment support: edo_id, official_receipt_path, fee_amount, version, and indexes';
    }

    public function up(Schema $schema): void
    {
        // Add edo_id column to payments_edo table
        $this->addSql('
            ALTER TABLE payments_edo 
            ADD edo_id INT DEFAULT NULL
        ');

        // Add foreign key constraint
        $this->addSql('
            ALTER TABLE payments_edo 
            ADD CONSTRAINT fk_payments_edo_edo 
            FOREIGN KEY (edo_id) REFERENCES electronic_delivery_orders(id) 
            ON DELETE CASCADE
        ');

        // Add official_receipt_path column to payments_edo table
        $this->addSql('
            ALTER TABLE payments_edo 
            ADD official_receipt_path VARCHAR(500) DEFAULT NULL
        ');

        // Add fee_amount column to electronic_delivery_orders table
        $this->addSql('
            ALTER TABLE electronic_delivery_orders 
            ADD fee_amount DECIMAL(10, 2) DEFAULT NULL
        ');

        // Add version column to electronic_delivery_orders table (if not exists)
        // Note: The entity already has version column, but we check if it needs to be added
        $this->addSql('
            ALTER TABLE electronic_delivery_orders 
            MODIFY version INT DEFAULT 1 NOT NULL
        ');

        // Create index on edo_id for fast eDO-based queries
        $this->addSql('
            CREATE INDEX idx_payments_edo_edo_id ON payments_edo(edo_id)
        ');

        // Create index on status for fast status-based filtering (if not exists)
        // Note: This index already exists based on entity annotations, but we ensure it's there
        $this->addSql('
            CREATE INDEX IF NOT EXISTS idx_payments_edo_status ON payments_edo(status)
        ');

        // Create index on electronic_delivery_orders status (if not exists)
        $this->addSql('
            CREATE INDEX IF NOT EXISTS idx_edos_status ON electronic_delivery_orders(status)
        ');

        // Create index on electronic_delivery_orders manifest_id (if not exists)
        $this->addSql('
            CREATE INDEX IF NOT EXISTS idx_edos_manifest ON electronic_delivery_orders(manifest_id)
        ');
    }

    public function down(Schema $schema): void
    {
        // Drop foreign key constraint first (before dropping index)
        $this->addSql('ALTER TABLE payments_edo DROP FOREIGN KEY fk_payments_edo_edo');

        // Drop indexes
        $this->addSql('DROP INDEX idx_payments_edo_edo_id ON payments_edo');

        // Drop columns from payments_edo
        $this->addSql('
            ALTER TABLE payments_edo 
            DROP edo_id,
            DROP official_receipt_path
        ');

        // Drop fee_amount column from electronic_delivery_orders
        $this->addSql('
            ALTER TABLE electronic_delivery_orders 
            DROP fee_amount
        ');
    }
}
