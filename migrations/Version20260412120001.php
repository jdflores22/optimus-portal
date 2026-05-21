<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Data Migration: Move manifest_access payments from payments to payments_edo
 * This migration handles the complete data transfer and relationship updates
 */
final class Version20260412120001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Migrate manifest_access payment records from payments table to payments_edo table and update electronic_delivery_orders references';
    }

    public function up(Schema $schema): void
    {
        // Step 1: Copy manifest_access payments to payments_edo
        $this->addSql('
            INSERT INTO payments_edo (
                manifest_id, 
                amount, 
                receipt_file_path, 
                status,
                submitted_by_id, 
                validated_by_id, 
                validated_at,
                rejection_reason, 
                created_at
            )
            SELECT
                manifest_id, 
                amount, 
                receipt_file_path, 
                status,
                submitted_by_id, 
                validated_by_id, 
                validated_at,
                rejection_reason, 
                created_at
            FROM payments
            WHERE payment_type = \'manifest_access\'
        ');

        // Step 2: Add edo_payment_id column to electronic_delivery_orders (nullable initially)
        $this->addSql('
            ALTER TABLE electronic_delivery_orders 
            ADD COLUMN edo_payment_id INT DEFAULT NULL
        ');

        // Step 3: Update electronic_delivery_orders to reference payments_edo
        // Match by manifest_id, amount, and created_at to ensure correct mapping
        $this->addSql('
            UPDATE electronic_delivery_orders edo
            INNER JOIN payments p ON edo.payment_id = p.id
            INNER JOIN payments_edo pe ON pe.manifest_id = p.manifest_id
                AND pe.amount = p.amount
                AND pe.created_at = p.created_at
            SET edo.edo_payment_id = pe.id
            WHERE p.payment_type = \'manifest_access\'
        ');

        // Step 4: Add foreign key constraint for edo_payment_id
        $this->addSql('
            ALTER TABLE electronic_delivery_orders
            ADD CONSTRAINT FK_edos_edo_payment 
            FOREIGN KEY (edo_payment_id) REFERENCES payments_edo (id) ON DELETE RESTRICT
        ');

        // Step 5: Drop old payment_id foreign key constraint
        $this->addSql('
            ALTER TABLE electronic_delivery_orders
            DROP FOREIGN KEY FK_edos_payment
        ');

        // Step 6: Remove old payment_id column
        $this->addSql('
            ALTER TABLE electronic_delivery_orders
            DROP COLUMN payment_id
        ');

        // Step 7: Make edo_payment_id NOT NULL now that data is migrated
        $this->addSql('
            ALTER TABLE electronic_delivery_orders
            MODIFY COLUMN edo_payment_id INT NOT NULL
        ');

        // Step 8: Add unique constraint on edo_payment_id
        $this->addSql('
            ALTER TABLE electronic_delivery_orders
            ADD UNIQUE INDEX UNIQ_edos_edo_payment (edo_payment_id)
        ');

        // Step 9: Delete manifest_access payments from payments table
        $this->addSql('
            DELETE FROM payments WHERE payment_type = \'manifest_access\'
        ');
    }

    public function down(Schema $schema): void
    {
        // Rollback Step 1: Restore payment_id column
        $this->addSql('
            ALTER TABLE electronic_delivery_orders
            ADD COLUMN payment_id INT DEFAULT NULL
        ');

        // Rollback Step 2: Copy payments_edo back to payments
        $this->addSql('
            INSERT INTO payments (
                manifest_id,
                payment_type,
                amount,
                receipt_file_path,
                status,
                submitted_by_id,
                validated_by_id,
                validated_at,
                rejection_reason,
                created_at
            )
            SELECT
                manifest_id,
                \'manifest_access\' as payment_type,
                amount,
                receipt_file_path,
                status,
                submitted_by_id,
                validated_by_id,
                validated_at,
                rejection_reason,
                created_at
            FROM payments_edo
        ');

        // Rollback Step 3: Update electronic_delivery_orders to reference payments
        $this->addSql('
            UPDATE electronic_delivery_orders edo
            INNER JOIN payments_edo pe ON edo.edo_payment_id = pe.id
            INNER JOIN payments p ON p.manifest_id = pe.manifest_id
                AND p.amount = pe.amount
                AND p.created_at = pe.created_at
                AND p.payment_type = \'manifest_access\'
            SET edo.payment_id = p.id
        ');

        // Rollback Step 4: Drop edo_payment_id constraint and column
        $this->addSql('
            ALTER TABLE electronic_delivery_orders
            DROP FOREIGN KEY FK_edos_edo_payment
        ');

        $this->addSql('
            ALTER TABLE electronic_delivery_orders
            DROP INDEX UNIQ_edos_edo_payment
        ');

        $this->addSql('
            ALTER TABLE electronic_delivery_orders
            DROP COLUMN edo_payment_id
        ');

        // Rollback Step 5: Restore payment_id foreign key
        $this->addSql('
            ALTER TABLE electronic_delivery_orders
            MODIFY COLUMN payment_id INT NOT NULL
        ');

        $this->addSql('
            ALTER TABLE electronic_delivery_orders
            ADD CONSTRAINT FK_edos_payment 
            FOREIGN KEY (payment_id) REFERENCES payments (id) ON DELETE RESTRICT
        ');

        $this->addSql('
            ALTER TABLE electronic_delivery_orders
            ADD UNIQUE INDEX UNIQ_edos_payment (payment_id)
        ');

        // Rollback Step 6: Delete from payments_edo
        $this->addSql('
            DELETE FROM payments_edo
        ');
    }
}
