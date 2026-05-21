<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create payments table for manifest access and final payments
 */
final class Version20260405120003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create payments table with payment type and status management';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE payments (
            id INT AUTO_INCREMENT NOT NULL,
            manifest_id INT NOT NULL,
            payment_type VARCHAR(50) NOT NULL,
            amount DECIMAL(10, 2) NOT NULL,
            receipt_file_path VARCHAR(500) NOT NULL,
            status VARCHAR(50) NOT NULL DEFAULT \'pending_validation\',
            submitted_by_id INT NOT NULL,
            validated_by_id INT DEFAULT NULL,
            validated_at DATETIME DEFAULT NULL,
            rejection_reason TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            INDEX idx_payments_manifest (manifest_id),
            INDEX idx_payments_type_status (payment_type, status),
            INDEX idx_payments_submitted_by (submitted_by_id),
            INDEX idx_payments_created_at (created_at),
            CONSTRAINT FK_payments_manifest FOREIGN KEY (manifest_id) REFERENCES manifests (id) ON DELETE CASCADE,
            CONSTRAINT FK_payments_submitted_by FOREIGN KEY (submitted_by_id) REFERENCES users (id) ON DELETE RESTRICT,
            CONSTRAINT FK_payments_validated_by FOREIGN KEY (validated_by_id) REFERENCES users (id) ON DELETE SET NULL
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Add payment type constraint
        $this->addSql('ALTER TABLE payments ADD CONSTRAINT chk_payment_type 
            CHECK (payment_type IN (\'manifest_access\', \'final_payment\'))');

        // Add payment status constraint
        $this->addSql('ALTER TABLE payments ADD CONSTRAINT chk_payment_status 
            CHECK (status IN (\'pending_validation\', \'verified\', \'rejected\'))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE payments');
    }
}
