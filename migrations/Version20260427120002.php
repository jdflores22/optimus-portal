<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration to add performance optimization indexes
 * Task 17.1: Add database indexes for query optimization
 */
final class Version20260427120002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add performance optimization indexes for CY allocation queries';
    }

    public function up(Schema $schema): void
    {
        // Composite index on containers for filtering by allocation and status
        // Optimizes queries that filter containers by both cy_allocation_id and allocation_status
        $this->addSql('CREATE INDEX IDX_CONTAINERS_CY_ALLOCATION_STATUS 
            ON containers(cy_allocation_id, allocation_status)');
        
        // Composite index on container_allocation_audit for audit trail queries
        // Optimizes queries that retrieve audit history for a specific container ordered by time
        $this->addSql('CREATE INDEX IDX_AUDIT_CONTAINER_CHANGED_AT 
            ON container_allocation_audit(container_id, changed_at)');
        
        // Composite index on shipping_line_terminal_allocations for filtering by shipping line and terminal
        // Optimizes queries that retrieve allocations for a specific shipping line at a specific terminal
        $this->addSql('CREATE INDEX IDX_SLTA_SHIPPING_LINE_TERMINAL 
            ON shipping_line_terminal_allocations(shipping_line_id, terminal_id)');
    }

    public function down(Schema $schema): void
    {
        // Drop composite indexes
        $this->addSql('DROP INDEX IDX_SLTA_SHIPPING_LINE_TERMINAL 
            ON shipping_line_terminal_allocations');
        $this->addSql('DROP INDEX IDX_AUDIT_CONTAINER_CHANGED_AT 
            ON container_allocation_audit');
        $this->addSql('DROP INDEX IDX_CONTAINERS_CY_ALLOCATION_STATUS 
            ON containers');
    }
}
