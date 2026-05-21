<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Container-Based eDO Workflow - Task 1.6: Create EDOPaymentReceipt entity
 */
final class Version20260420120005 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create edo_payment_receipts table for payment receipt submissions';
    }

    public function up(Schema $schema): void
    {
        // Create edo_payment_receipts table
        $this->addSql('CREATE TABLE edo_payment_receipts (
            id INT AUTO_INCREMENT NOT NULL,
            billing_id INT NOT NULL,
            receipt_file_path VARCHAR(500) NOT NULL,
            submitted_by_id INT NOT NULL,
            submitted_at DATETIME NOT NULL,
            confirmed_by_id INT DEFAULT NULL,
            confirmed_at DATETIME DEFAULT NULL,
            status VARCHAR(20) NOT NULL,
            rejection_reason TEXT DEFAULT NULL,
            UNIQUE INDEX UNIQ_edo_payment_billing (billing_id),
            INDEX idx_edo_payment_billing (billing_id),
            INDEX idx_edo_payment_submitter (submitted_by_id),
            INDEX idx_edo_payment_status (status),
            INDEX idx_edo_payment_submitted_at (submitted_at),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Add foreign keys
        $this->addSql('ALTER TABLE edo_payment_receipts 
            ADD CONSTRAINT FK_edo_payment_billing 
            FOREIGN KEY (billing_id) REFERENCES edo_billings (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE edo_payment_receipts 
            ADD CONSTRAINT FK_edo_payment_submitted_by 
            FOREIGN KEY (submitted_by_id) REFERENCES users (id) ON DELETE RESTRICT');

        $this->addSql('ALTER TABLE edo_payment_receipts 
            ADD CONSTRAINT FK_edo_payment_confirmed_by 
            FOREIGN KEY (confirmed_by_id) REFERENCES users (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        // Drop foreign keys
        $this->addSql('ALTER TABLE edo_payment_receipts DROP FOREIGN KEY FK_edo_payment_confirmed_by');
        $this->addSql('ALTER TABLE edo_payment_receipts DROP FOREIGN KEY FK_edo_payment_submitted_by');
        $this->addSql('ALTER TABLE edo_payment_receipts DROP FOREIGN KEY FK_edo_payment_billing');

        // Drop table
        $this->addSql('DROP TABLE edo_payment_receipts');
    }
}
