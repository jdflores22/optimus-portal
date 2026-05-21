<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Container-Based eDO Workflow - Task 19.1: Add performance indexes
 */
final class Version20260420120007 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add performance indexes for eDO workflow queries';
    }

    public function up(Schema $schema): void
    {
        // Add indexes on electronic_delivery_orders for performance
        $this->addSql('CREATE INDEX idx_edo_number ON electronic_delivery_orders (edo_number)');
        $this->addSql('CREATE INDEX idx_edo_status ON electronic_delivery_orders (status)');
        
        // Composite index for expiration queries (status + expires_at)
        $this->addSql('CREATE INDEX idx_edo_status_expires ON electronic_delivery_orders (status, expires_at)');
        
        // Add indexes on edo_audit_logs for query performance
        $this->addSql('CREATE INDEX idx_audit_container ON edo_audit_logs (container_id)');
        $this->addSql('CREATE INDEX idx_audit_edo ON edo_audit_logs (edo_id)');
        $this->addSql('CREATE INDEX idx_audit_event_type ON edo_audit_logs (event_type)');
        $this->addSql('CREATE INDEX idx_audit_timestamp ON edo_audit_logs (timestamp)');
        
        // Add index on regeneration_requests for status queries
        $this->addSql('CREATE INDEX idx_regen_status ON regeneration_requests (status)');
    }

    public function down(Schema $schema): void
    {
        // Drop indexes in reverse order
        $this->addSql('DROP INDEX idx_regen_status ON regeneration_requests');
        
        $this->addSql('DROP INDEX idx_audit_timestamp ON edo_audit_logs');
        $this->addSql('DROP INDEX idx_audit_event_type ON edo_audit_logs');
        $this->addSql('DROP INDEX idx_audit_edo ON edo_audit_logs');
        $this->addSql('DROP INDEX idx_audit_container ON edo_audit_logs');
        
        $this->addSql('DROP INDEX idx_edo_status_expires ON electronic_delivery_orders');
        $this->addSql('DROP INDEX idx_edo_status ON electronic_delivery_orders');
        $this->addSql('DROP INDEX idx_edo_number ON electronic_delivery_orders');
    }
}
