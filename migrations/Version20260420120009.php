<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Performance optimization migration: Add database indexes for container-based eDO workflow
 */
final class Version20260420120009 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add performance indexes for ElectronicDeliveryOrder, EDOAuditLog, and RegenerationRequest tables';
    }

    public function up(Schema $schema): void
    {
        // Add index on container_id for ElectronicDeliveryOrder (if not exists)
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_edos_container ON electronic_delivery_orders (container_id)');
        
        // Add composite index on (status, expires_at) for expiration queries
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_edos_status_expires ON electronic_delivery_orders (status, expires_at)');
        
        // Add indexes on EDOAuditLog for container_id and edo_id
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_audit_container ON edo_audit_logs (container_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_audit_edo ON edo_audit_logs (edo_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_audit_event_type ON edo_audit_logs (event_type)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_audit_timestamp ON edo_audit_logs (timestamp)');
        
        // Add index on RegenerationRequest.status
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_regen_status ON regeneration_requests (status)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_regen_edo ON regeneration_requests (edo_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_regen_requester ON regeneration_requests (requester_id)');
    }

    public function down(Schema $schema): void
    {
        // Remove indexes in reverse order
        $this->addSql('DROP INDEX IF EXISTS idx_regen_requester');
        $this->addSql('DROP INDEX IF EXISTS idx_regen_edo');
        $this->addSql('DROP INDEX IF EXISTS idx_regen_status');
        
        $this->addSql('DROP INDEX IF EXISTS idx_audit_timestamp');
        $this->addSql('DROP INDEX IF EXISTS idx_audit_event_type');
        $this->addSql('DROP INDEX IF EXISTS idx_audit_edo');
        $this->addSql('DROP INDEX IF EXISTS idx_audit_container');
        
        $this->addSql('DROP INDEX IF EXISTS idx_edos_status_expires');
        $this->addSql('DROP INDEX IF EXISTS idx_edos_container');
    }
}
