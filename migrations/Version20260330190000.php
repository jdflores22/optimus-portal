<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration to add shipping_line_id foreign key to pre_advice_requests table
 * This ensures all entities in the hierarchy have direct shipping line relationships
 */
final class Version20260330190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add shipping_line_id foreign key to pre_advice_requests table for dynamic hierarchy filtering';
    }

    public function up(Schema $schema): void
    {
        // Add shipping_line_id column to pre_advice_requests table
        $this->addSql('ALTER TABLE pre_advice_requests ADD COLUMN shipping_line_id INT DEFAULT NULL');
        
        // Add foreign key constraint
        $this->addSql('ALTER TABLE pre_advice_requests ADD CONSTRAINT FK_pre_advice_requests_shipping_line_id 
            FOREIGN KEY (shipping_line_id) REFERENCES shipping_lines(id) ON DELETE SET NULL');
        
        // Add index for performance
        $this->addSql('CREATE INDEX IDX_pre_advice_requests_shipping_line_id ON pre_advice_requests(shipping_line_id)');
        
        // Populate shipping_line_id from trucker's shipping line admin relationship
        // This ensures existing data has the correct shipping line association
        // Truckers inherit from User, so they're in the users table with their shipping_line_admin_id
        $this->addSql('
            UPDATE pre_advice_requests par
            INNER JOIN users trucker ON par.trucker_id = trucker.id
            INNER JOIN users admin ON trucker.shipping_line_admin_id = admin.id
            SET par.shipping_line_id = admin.managed_shipping_line_id
            WHERE admin.managed_shipping_line_id IS NOT NULL
        ');
    }

    public function down(Schema $schema): void
    {
        // Drop foreign key constraint
        $this->addSql('ALTER TABLE pre_advice_requests DROP FOREIGN KEY FK_pre_advice_requests_shipping_line_id');
        
        // Drop index
        $this->addSql('DROP INDEX IDX_pre_advice_requests_shipping_line_id ON pre_advice_requests');
        
        // Drop column
        $this->addSql('ALTER TABLE pre_advice_requests DROP COLUMN shipping_line_id');
    }
}
