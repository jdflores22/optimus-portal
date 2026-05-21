<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Data migration for eDO Release Workflow
 * 
 * Migrates:
 * - Existing eDOs to 'released' status
 * - Manifests from PENDING_PAYMENT/PAYMENT_VERIFIED to appropriate states
 * - Inserts default payment fee configuration (₱500.00)
 * - Creates workflow state history entries for migrated records
 * 
 * Requirements: 13.1, 13.2, 13.3, 13.4, 16.8
 */
final class Version20260407120001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Data migration for eDO Release Workflow: migrate existing eDOs, manifests, and insert default payment fee configuration';
    }

    public function up(Schema $schema): void
    {
        // 1. Migrate existing eDOs to 'released' status
        // All existing eDOs should be considered released since they were generated under the old workflow
        $this->addSql("
            UPDATE electronic_delivery_orders 
            SET status = 'released' 
            WHERE status = 'pending_release'
        ");

        // 2. Migrate manifests from PENDING_PAYMENT to appropriate states
        // If NOA has been generated, move to NOA_GENERATED state
        $this->addSql("
            UPDATE manifests m
            SET workflow_state = 'noa_generated'
            WHERE workflow_state = 'pending_payment'
            AND EXISTS (
                SELECT 1 FROM noa_documents n 
                WHERE n.manifest_id = m.id
            )
        ");

        // If NOA has not been generated, move to MANIFEST_UPLOADED state
        $this->addSql("
            UPDATE manifests m
            SET workflow_state = 'manifest_uploaded'
            WHERE workflow_state = 'pending_payment'
            AND NOT EXISTS (
                SELECT 1 FROM noa_documents n 
                WHERE n.manifest_id = m.id
            )
        ");

        // 3. Migrate manifests from PAYMENT_VERIFIED to appropriate states
        // If NOA has been generated, move to NOA_GENERATED state
        $this->addSql("
            UPDATE manifests m
            SET workflow_state = 'noa_generated'
            WHERE workflow_state = 'payment_verified'
            AND EXISTS (
                SELECT 1 FROM noa_documents n 
                WHERE n.manifest_id = m.id
            )
        ");

        // If NOA has not been generated, move to MANIFEST_UPLOADED state
        $this->addSql("
            UPDATE manifests m
            SET workflow_state = 'manifest_uploaded'
            WHERE workflow_state = 'payment_verified'
            AND NOT EXISTS (
                SELECT 1 FROM noa_documents n 
                WHERE n.manifest_id = m.id
            )
        ");

        // 4. Insert default payment fee configuration (₱500.00)
        // Use the first SYSTEM_ADMIN user found, or the first user if no SYSTEM_ADMIN exists
        $this->addSql("
            INSERT INTO payment_fee_configurations (fee_type, amount, configured_by_id, configured_at, previous_amount)
            SELECT 
                'manifest_access' as fee_type,
                500.00 as amount,
                COALESCE(
                    (SELECT id FROM users WHERE type = 'system_admin' LIMIT 1),
                    (SELECT id FROM users LIMIT 1)
                ) as configured_by_id,
                NOW() as configured_at,
                NULL as previous_amount
            FROM DUAL
            WHERE NOT EXISTS (
                SELECT 1 FROM payment_fee_configurations WHERE fee_type = 'manifest_access'
            )
        ");

        // 5. Create workflow state history entries for migrated records
        // This creates audit trail entries for manifests that were migrated from old states
        $this->addSql("
            INSERT INTO workflow_state_history (manifest_id, from_state, to_state, actor_id, created_at, notes)
            SELECT 
                m.id as manifest_id,
                'pending_payment' as from_state,
                m.workflow_state as to_state,
                m.created_by_id as actor_id,
                NOW() as created_at,
                'Migrated from old workflow state during eDO Release Workflow implementation' as notes
            FROM manifests m
            WHERE m.workflow_state IN ('manifest_uploaded', 'noa_generated')
            AND EXISTS (
                SELECT 1 FROM information_schema.tables 
                WHERE table_schema = DATABASE()
                AND table_name = 'workflow_state_history'
            )
            AND NOT EXISTS (
                SELECT 1 FROM workflow_state_history wsh
                WHERE wsh.manifest_id = m.id 
                AND wsh.notes LIKE '%Migrated from old workflow state%'
            )
        ");
    }

    public function down(Schema $schema): void
    {
        // Note: Data migrations are generally not reversible
        // We cannot reliably restore the previous state without data loss
        // This down migration is provided for reference only
        
        $this->addSql("
            -- Cannot reliably reverse data migration
            -- Original workflow states (pending_payment, payment_verified) have been removed
            -- Existing eDOs cannot be reverted to pending_release without losing release information
            SELECT 'Data migration cannot be reversed' as warning
        ");
    }
}
