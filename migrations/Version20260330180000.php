<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration to add shipping_line_id foreign key to containers table
 * Task 14.1: Add ShippingLine relationship to Container entity
 */
final class Version20260330180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add shipping_line_id foreign key to containers table for Terminal Team filtering';
    }

    public function up(Schema $schema): void
    {
        // Add shipping_line_id column to containers table
        $this->addSql('ALTER TABLE containers ADD COLUMN shipping_line_id INT DEFAULT NULL');
        
        // Add foreign key constraint
        $this->addSql('ALTER TABLE containers ADD CONSTRAINT FK_containers_shipping_line_id 
            FOREIGN KEY (shipping_line_id) REFERENCES shipping_lines(id) ON DELETE SET NULL');
        
        // Add index for performance
        $this->addSql('CREATE INDEX IDX_containers_shipping_line_id ON containers(shipping_line_id)');
    }

    public function down(Schema $schema): void
    {
        // Drop foreign key constraint
        $this->addSql('ALTER TABLE containers DROP FOREIGN KEY FK_containers_shipping_line_id');
        
        // Drop index
        $this->addSql('DROP INDEX IDX_containers_shipping_line_id ON containers');
        
        // Drop column
        $this->addSql('ALTER TABLE containers DROP COLUMN shipping_line_id');
    }
}
