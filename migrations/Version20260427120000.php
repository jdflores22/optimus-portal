<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration to add CY allocation fields to Container entity
 * Supports per-container CY allocation management
 */
final class Version20260427120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add CY allocation fields to containers table for per-container allocation management';
    }

    public function up(Schema $schema): void
    {
        // Add cy_allocation_id foreign key to containers table
        $this->addSql('ALTER TABLE containers ADD COLUMN cy_allocation_id INT NULL');
        
        // Add allocation_status enum column with default value
        $this->addSql("ALTER TABLE containers ADD COLUMN allocation_status VARCHAR(20) DEFAULT 'pre_forecast' NOT NULL");
        
        // Add allocated_at timestamp column
        $this->addSql('ALTER TABLE containers ADD COLUMN allocated_at DATETIME NULL');
        
        // Add allocation_locked_at timestamp column
        $this->addSql('ALTER TABLE containers ADD COLUMN allocation_locked_at DATETIME NULL');
        
        // Create foreign key constraint
        $this->addSql('ALTER TABLE containers ADD CONSTRAINT FK_CONTAINER_CY_ALLOCATION 
            FOREIGN KEY (cy_allocation_id) 
            REFERENCES shipping_line_terminal_allocations(id) 
            ON DELETE SET NULL');
        
        // Create indexes for performance
        $this->addSql('CREATE INDEX IDX_CONTAINERS_CY_ALLOCATION ON containers(cy_allocation_id)');
        $this->addSql('CREATE INDEX IDX_CONTAINERS_ALLOCATION_STATUS ON containers(allocation_status)');
    }

    public function down(Schema $schema): void
    {
        // Drop indexes
        $this->addSql('DROP INDEX IDX_CONTAINERS_ALLOCATION_STATUS ON containers');
        $this->addSql('DROP INDEX IDX_CONTAINERS_CY_ALLOCATION ON containers');
        
        // Drop foreign key constraint
        $this->addSql('ALTER TABLE containers DROP FOREIGN KEY FK_CONTAINER_CY_ALLOCATION');
        
        // Drop columns
        $this->addSql('ALTER TABLE containers DROP COLUMN allocation_locked_at');
        $this->addSql('ALTER TABLE containers DROP COLUMN allocated_at');
        $this->addSql('ALTER TABLE containers DROP COLUMN allocation_status');
        $this->addSql('ALTER TABLE containers DROP COLUMN cy_allocation_id');
    }
}
