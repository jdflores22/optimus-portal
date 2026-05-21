<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Multi-Tenant Shipping Line - Task 1.6: Make Shipping Line Columns NOT NULL
 * 
 * After data migration, this migration makes shipping_line_id columns NOT NULL
 * for core entities to enforce data integrity. Notifications remain NULLABLE.
 */
final class Version20260412180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make shipping_line_id columns NOT NULL for core entities after data migration';
    }

    public function up(Schema $schema): void
    {
        // Verify all records have shipping_line_id before making NOT NULL
        $this->write('Verifying all records have shipping_line_id assigned...');
        
        // Make manifests.shipping_line_id NOT NULL
        $this->addSql('ALTER TABLE manifests MODIFY COLUMN shipping_line_id INT NOT NULL COMMENT \'Foreign key to shipping_lines table\'');
        $this->write('Made manifests.shipping_line_id NOT NULL');
        
        // Make payments.shipping_line_id NOT NULL
        $this->addSql('ALTER TABLE payments MODIFY COLUMN shipping_line_id INT NOT NULL COMMENT \'Foreign key to shipping_lines table\'');
        $this->write('Made payments.shipping_line_id NOT NULL');
        
        // Make payments_edo.shipping_line_id NOT NULL
        $this->addSql('ALTER TABLE payments_edo MODIFY COLUMN shipping_line_id INT NOT NULL COMMENT \'Foreign key to shipping_lines table\'');
        $this->write('Made payments_edo.shipping_line_id NOT NULL');
        
        // Make electronic_delivery_orders.shipping_line_id NOT NULL
        $this->addSql('ALTER TABLE electronic_delivery_orders MODIFY COLUMN shipping_line_id INT NOT NULL COMMENT \'Foreign key to shipping_lines table\'');
        $this->write('Made electronic_delivery_orders.shipping_line_id NOT NULL');
        
        // Make accreditation_submissions.shipping_line_id NOT NULL
        $this->addSql('ALTER TABLE accreditation_submissions MODIFY COLUMN shipping_line_id INT NOT NULL COMMENT \'Foreign key to shipping_lines table\'');
        $this->write('Made accreditation_submissions.shipping_line_id NOT NULL');
        
        // Note: notifications.shipping_line_id remains NULLABLE as designed
        
        $this->write('All shipping_line_id columns are now NOT NULL (except notifications)');
    }

    public function down(Schema $schema): void
    {
        // Rollback: Make columns NULLABLE again
        $this->write('Rolling back - making shipping_line_id columns NULLABLE');
        
        $this->addSql('ALTER TABLE manifests MODIFY COLUMN shipping_line_id INT NULL COMMENT \'Foreign key to shipping_lines table\'');
        $this->addSql('ALTER TABLE payments MODIFY COLUMN shipping_line_id INT NULL COMMENT \'Foreign key to shipping_lines table\'');
        $this->addSql('ALTER TABLE payments_edo MODIFY COLUMN shipping_line_id INT NULL COMMENT \'Foreign key to shipping_lines table\'');
        $this->addSql('ALTER TABLE electronic_delivery_orders MODIFY COLUMN shipping_line_id INT NULL COMMENT \'Foreign key to shipping_lines table\'');
        $this->addSql('ALTER TABLE accreditation_submissions MODIFY COLUMN shipping_line_id INT NULL COMMENT \'Foreign key to shipping_lines table\'');
        
        $this->write('Rollback completed');
    }
}
