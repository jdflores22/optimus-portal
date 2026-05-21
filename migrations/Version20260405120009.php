<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Performance optimization: Add database indexes for query optimization
 * - Verify all foreign keys are indexed
 * - Add composite indexes for common query patterns
 * - Add indexes on workflow_state and payment status
 */
final class Version20260405120009 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add performance indexes for manifest payment workflow queries';
    }

    public function up(Schema $schema): void
    {
        // Composite index for payment queries by manifest and type
        $this->addSql('CREATE INDEX idx_payments_manifest_type ON payments(manifest_id, payment_type)');
        
        // Composite index for payment queries by status and created date (for pending queues)
        $this->addSql('CREATE INDEX idx_payments_status_created ON payments(status, created_at)');
        
        // Composite index for manifest queries by workflow state and created date
        $this->addSql('CREATE INDEX idx_manifests_state_created ON manifests(workflow_state, created_at)');
        
        // Composite index for manifest queries by consignee and workflow state
        $this->addSql('CREATE INDEX idx_manifests_consignee_state ON manifests(consignee_id, workflow_state) WHERE consignee_id IS NOT NULL');
        
        // Composite index for manifest queries by broker and workflow state
        $this->addSql('CREATE INDEX idx_manifests_broker_state ON manifests(broker_id, workflow_state) WHERE broker_id IS NOT NULL');
        
        // Index on NOA documents created_at for reporting
        $this->addSql('CREATE INDEX idx_noa_documents_created_at ON noa_documents(created_at)');
        
        // Index on EDO generated_at for reporting
        $this->addSql('CREATE INDEX idx_edos_generated_at ON electronic_delivery_orders(generated_at)');
        
        // Composite index for workflow history queries by manifest and date
        $this->addSql('CREATE INDEX idx_workflow_history_manifest_date ON workflow_state_history(manifest_id, created_at DESC)');
        
        // Index on payment validated_at for reporting
        $this->addSql('CREATE INDEX idx_payments_validated_at ON payments(validated_at) WHERE validated_at IS NOT NULL');
        
        // Index on billing generated_by for audit queries
        $this->addSql('CREATE INDEX idx_billings_generated_by ON billings(generated_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_payments_manifest_type');
        $this->addSql('DROP INDEX IF EXISTS idx_payments_status_created');
        $this->addSql('DROP INDEX IF EXISTS idx_manifests_state_created');
        $this->addSql('DROP INDEX IF EXISTS idx_manifests_consignee_state');
        $this->addSql('DROP INDEX IF EXISTS idx_manifests_broker_state');
        $this->addSql('DROP INDEX IF EXISTS idx_noa_documents_created_at');
        $this->addSql('DROP INDEX IF EXISTS idx_edos_generated_at');
        $this->addSql('DROP INDEX IF EXISTS idx_workflow_history_manifest_date');
        $this->addSql('DROP INDEX IF EXISTS idx_payments_validated_at');
        $this->addSql('DROP INDEX IF EXISTS idx_billings_generated_by');
    }
}
