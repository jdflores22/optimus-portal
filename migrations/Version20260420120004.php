<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Container-Based eDO Workflow - Task 1.5: Create EDOBilling entity
 */
final class Version20260420120004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create edo_billings table for eDO expired days billing';
    }

    public function up(Schema $schema): void
    {
        // Create edo_billings table
        $this->addSql('CREATE TABLE edo_billings (
            id INT AUTO_INCREMENT NOT NULL,
            regeneration_request_id INT NOT NULL,
            expired_days INT NOT NULL,
            per_day_rate DECIMAL(10, 2) NOT NULL,
            total_amount DECIMAL(10, 2) NOT NULL,
            billing_document_path VARCHAR(500) DEFAULT NULL,
            generated_by_id INT NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE INDEX UNIQ_edo_billing_regen_req (regeneration_request_id),
            INDEX idx_edo_billing_regen_req (regeneration_request_id),
            INDEX idx_edo_billing_created_at (created_at),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Add foreign keys
        $this->addSql('ALTER TABLE edo_billings 
            ADD CONSTRAINT FK_edo_billing_regen_req 
            FOREIGN KEY (regeneration_request_id) REFERENCES regeneration_requests (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE edo_billings 
            ADD CONSTRAINT FK_edo_billing_generated_by 
            FOREIGN KEY (generated_by_id) REFERENCES users (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        // Drop foreign keys
        $this->addSql('ALTER TABLE edo_billings DROP FOREIGN KEY FK_edo_billing_generated_by');
        $this->addSql('ALTER TABLE edo_billings DROP FOREIGN KEY FK_edo_billing_regen_req');

        // Drop table
        $this->addSql('DROP TABLE edo_billings');
    }
}
