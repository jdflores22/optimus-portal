<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Multi-Tenant Shipping Line - Task 1.2: Add Shipping Line to Core Entities
 * 
 * Adds shipping_line_id foreign key to manifests, payments, payments_edo,
 * electronic_delivery_orders, and notifications tables.
 * 
 * Note: Columns are initially NULLABLE to allow data migration.
 * They will be made NOT NULL in a later migration after data migration.
 */
final class Version20260412140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add shipping_line_id foreign key to core entities (manifests, payments, payments_edo, electronic_delivery_orders, notifications)';
    }

    public function up(Schema $schema): void
    {
        // Add shipping_line_id to manifests table
        $this->addSql('ALTER TABLE manifests ADD COLUMN shipping_line_id INT NULL COMMENT \'Foreign key to shipping_lines table\'');
        $this->addSql('ALTER TABLE manifests ADD CONSTRAINT fk_manifests_shipping_line FOREIGN KEY (shipping_line_id) REFERENCES shipping_lines(id) ON DELETE RESTRICT');
        $this->addSql('CREATE INDEX idx_manifests_shipping_line ON manifests (shipping_line_id)');

        // Add shipping_line_id to payments table
        $this->addSql('ALTER TABLE payments ADD COLUMN shipping_line_id INT NULL COMMENT \'Foreign key to shipping_lines table\'');
        $this->addSql('ALTER TABLE payments ADD CONSTRAINT fk_payments_shipping_line FOREIGN KEY (shipping_line_id) REFERENCES shipping_lines(id) ON DELETE RESTRICT');
        $this->addSql('CREATE INDEX idx_payments_shipping_line ON payments (shipping_line_id)');

        // Add shipping_line_id to payments_edo table
        $this->addSql('ALTER TABLE payments_edo ADD COLUMN shipping_line_id INT NULL COMMENT \'Foreign key to shipping_lines table\'');
        $this->addSql('ALTER TABLE payments_edo ADD CONSTRAINT fk_payments_edo_shipping_line FOREIGN KEY (shipping_line_id) REFERENCES shipping_lines(id) ON DELETE RESTRICT');
        $this->addSql('CREATE INDEX idx_payments_edo_shipping_line ON payments_edo (shipping_line_id)');

        // Add shipping_line_id to electronic_delivery_orders table
        $this->addSql('ALTER TABLE electronic_delivery_orders ADD COLUMN shipping_line_id INT NULL COMMENT \'Foreign key to shipping_lines table\'');
        $this->addSql('ALTER TABLE electronic_delivery_orders ADD CONSTRAINT fk_edos_shipping_line FOREIGN KEY (shipping_line_id) REFERENCES shipping_lines(id) ON DELETE RESTRICT');
        $this->addSql('CREATE INDEX idx_edos_shipping_line ON electronic_delivery_orders (shipping_line_id)');

        // Add shipping_line_id to notifications table (NULLABLE - remains nullable)
        $this->addSql('ALTER TABLE notifications ADD COLUMN shipping_line_id INT NULL COMMENT \'Foreign key to shipping_lines table (optional)\'');
        $this->addSql('ALTER TABLE notifications ADD CONSTRAINT fk_notifications_shipping_line FOREIGN KEY (shipping_line_id) REFERENCES shipping_lines(id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_notifications_shipping_line ON notifications (shipping_line_id)');
    }

    public function down(Schema $schema): void
    {
        // Drop foreign keys and indexes for notifications
        $this->addSql('ALTER TABLE notifications DROP FOREIGN KEY fk_notifications_shipping_line');
        $this->addSql('DROP INDEX idx_notifications_shipping_line ON notifications');
        $this->addSql('ALTER TABLE notifications DROP COLUMN shipping_line_id');

        // Drop foreign keys and indexes for electronic_delivery_orders
        $this->addSql('ALTER TABLE electronic_delivery_orders DROP FOREIGN KEY fk_edos_shipping_line');
        $this->addSql('DROP INDEX idx_edos_shipping_line ON electronic_delivery_orders');
        $this->addSql('ALTER TABLE electronic_delivery_orders DROP COLUMN shipping_line_id');

        // Drop foreign keys and indexes for payments_edo
        $this->addSql('ALTER TABLE payments_edo DROP FOREIGN KEY fk_payments_edo_shipping_line');
        $this->addSql('DROP INDEX idx_payments_edo_shipping_line ON payments_edo');
        $this->addSql('ALTER TABLE payments_edo DROP COLUMN shipping_line_id');

        // Drop foreign keys and indexes for payments
        $this->addSql('ALTER TABLE payments DROP FOREIGN KEY fk_payments_shipping_line');
        $this->addSql('DROP INDEX idx_payments_shipping_line ON payments');
        $this->addSql('ALTER TABLE payments DROP COLUMN shipping_line_id');

        // Drop foreign keys and indexes for manifests
        $this->addSql('ALTER TABLE manifests DROP FOREIGN KEY fk_manifests_shipping_line');
        $this->addSql('DROP INDEX idx_manifests_shipping_line ON manifests');
        $this->addSql('ALTER TABLE manifests DROP COLUMN shipping_line_id');
    }
}
