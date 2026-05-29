<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration to extend electronic_delivery_orders table
 * Task 1.3: Create electronic_delivery_orders table extension migration
 * Requirements: 4.1, 4.2, 4.3, 16.1, 16.3
 */
final class Version20260428120002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Extend electronic_delivery_orders table to store generator name and additional notes';
    }

    public function up(Schema $schema): void
    {
        // Add generated_by_name column to store generator's full name
        $this->addSql('
            ALTER TABLE electronic_delivery_orders
            ADD COLUMN generated_by_name VARCHAR(255) NULL AFTER released_by_id
        ');
        
        // Add additional_notes column for special instructions
        $this->addSql('
            ALTER TABLE electronic_delivery_orders
            ADD COLUMN additional_notes TEXT NULL AFTER cy_location
        ');
    }

    public function down(Schema $schema): void
    {
        // Drop columns
        $this->addSql('ALTER TABLE electronic_delivery_orders DROP COLUMN additional_notes');
        $this->addSql('ALTER TABLE electronic_delivery_orders DROP COLUMN generated_by_name');
    }
}
