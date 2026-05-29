<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remove unused tables from old regeneration workflow
 * Tables: edo_billings, edo_payment_receipts, regeneration_requests, edo_audit_logs
 */
final class Version20260527000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove unused tables from old eDO regeneration workflow (superseded by renewal workflow)';
    }

    public function up(Schema $schema): void
    {
        // Drop foreign keys first
        $this->addSql('ALTER TABLE edo_payment_receipts DROP FOREIGN KEY IF EXISTS FK_edo_payment_billing');
        $this->addSql('ALTER TABLE edo_billings DROP FOREIGN KEY IF EXISTS FK_edo_billing_generated_by');
        $this->addSql('ALTER TABLE edo_billings DROP FOREIGN KEY IF EXISTS FK_edo_billing_regen_req');
        $this->addSql('ALTER TABLE regeneration_requests DROP FOREIGN KEY IF EXISTS FK_regen_req_edo');
        $this->addSql('ALTER TABLE regeneration_requests DROP FOREIGN KEY IF EXISTS FK_regen_req_requester');

        // Drop tables (in correct order due to dependencies)
        $this->addSql('DROP TABLE IF EXISTS edo_payment_receipts');
        $this->addSql('DROP TABLE IF EXISTS edo_billings');
        $this->addSql('DROP TABLE IF EXISTS regeneration_requests');
        $this->addSql('DROP TABLE IF EXISTS edo_audit_logs');
    }

    public function down(Schema $schema): void
    {
        // Recreate regeneration_requests table
        $this->addSql('CREATE TABLE regeneration_requests (
            id INT AUTO_INCREMENT NOT NULL,
            edo_id INT NOT NULL,
            requester_id INT NOT NULL,
            status VARCHAR(50) NOT NULL,
            requested_at DATETIME NOT NULL,
            routed_to_accounting_at DATETIME DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            INDEX idx_regen_req_edo (edo_id),
            INDEX idx_regen_req_requester (requester_id),
            INDEX idx_regen_req_status (status),
            INDEX idx_regen_req_requested_at (requested_at),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Recreate edo_billings table
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

        // Recreate edo_payment_receipts table
        $this->addSql('CREATE TABLE edo_payment_receipts (
            id INT AUTO_INCREMENT NOT NULL,
            billing_id INT NOT NULL,
            receipt_file_path VARCHAR(500) NOT NULL,
            submitted_by_id INT NOT NULL,
            submitted_at DATETIME NOT NULL,
            status VARCHAR(50) NOT NULL,
            verified_by_id INT DEFAULT NULL,
            verified_at DATETIME DEFAULT NULL,
            verification_notes TEXT DEFAULT NULL,
            UNIQUE INDEX UNIQ_edo_payment_billing (billing_id),
            INDEX idx_edo_payment_status (status),
            INDEX idx_edo_payment_submitted_at (submitted_at),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Recreate edo_audit_logs table
        $this->addSql('CREATE TABLE edo_audit_logs (
            id INT AUTO_INCREMENT NOT NULL,
            edo_id INT DEFAULT NULL,
            action VARCHAR(100) NOT NULL,
            performed_by_id INT NOT NULL,
            performed_at DATETIME NOT NULL,
            details JSON DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            INDEX idx_edo_audit_edo (edo_id),
            INDEX idx_edo_audit_action (action),
            INDEX idx_edo_audit_performed_at (performed_at),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Recreate foreign keys
        $this->addSql('ALTER TABLE regeneration_requests 
            ADD CONSTRAINT FK_regen_req_edo 
            FOREIGN KEY (edo_id) REFERENCES electronic_delivery_orders (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE regeneration_requests 
            ADD CONSTRAINT FK_regen_req_requester 
            FOREIGN KEY (requester_id) REFERENCES users (id) ON DELETE RESTRICT');

        $this->addSql('ALTER TABLE edo_billings 
            ADD CONSTRAINT FK_edo_billing_regen_req 
            FOREIGN KEY (regeneration_request_id) REFERENCES regeneration_requests (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE edo_billings 
            ADD CONSTRAINT FK_edo_billing_generated_by 
            FOREIGN KEY (generated_by_id) REFERENCES users (id) ON DELETE RESTRICT');

        $this->addSql('ALTER TABLE edo_payment_receipts 
            ADD CONSTRAINT FK_edo_payment_billing 
            FOREIGN KEY (billing_id) REFERENCES edo_billings (id) ON DELETE CASCADE');
    }
}
