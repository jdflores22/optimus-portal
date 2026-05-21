<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration to create ContainerAllocationAudit table
 * Tracks all changes to container CY allocations for audit trail
 */
final class Version20260427120001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create container_allocation_audit table for tracking allocation changes';
    }

    public function up(Schema $schema): void
    {
        // Create container_allocation_audit table
        $this->addSql('CREATE TABLE container_allocation_audit (
            id INT AUTO_INCREMENT PRIMARY KEY,
            container_id INT NOT NULL,
            previous_allocation_id INT NULL,
            new_allocation_id INT NOT NULL,
            changed_by_id INT NOT NULL,
            changed_at DATETIME NOT NULL,
            change_type VARCHAR(50) NOT NULL,
            reason TEXT NULL,
            metadata JSON NULL,
            INDEX IDX_AUDIT_CONTAINER (container_id),
            INDEX IDX_AUDIT_CHANGED_AT (changed_at),
            INDEX IDX_AUDIT_CHANGE_TYPE (change_type),
            CONSTRAINT FK_AUDIT_CONTAINER 
                FOREIGN KEY (container_id) 
                REFERENCES containers(id) 
                ON DELETE CASCADE,
            CONSTRAINT FK_AUDIT_PREVIOUS_ALLOCATION 
                FOREIGN KEY (previous_allocation_id) 
                REFERENCES shipping_line_terminal_allocations(id) 
                ON DELETE SET NULL,
            CONSTRAINT FK_AUDIT_NEW_ALLOCATION 
                FOREIGN KEY (new_allocation_id) 
                REFERENCES shipping_line_terminal_allocations(id) 
                ON DELETE CASCADE,
            CONSTRAINT FK_AUDIT_CHANGED_BY 
                FOREIGN KEY (changed_by_id) 
                REFERENCES users(id) 
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function down(Schema $schema): void
    {
        // Drop the container_allocation_audit table
        $this->addSql('DROP TABLE container_allocation_audit');
    }
}
