<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create payments_edo table for eDO release payments (SYSTEM_ADMIN)
 * Separates eDO payment transactions from shipping line billing payments
 */
final class Version20260412120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create payments_edo table for eDO release payments handled by SYSTEM_ADMIN role';
    }

    public function up(Schema $schema): void
    {
        // Create payments_edo table
        $this->addSql('CREATE TABLE payments_edo (
            id INT AUTO_INCREMENT NOT NULL,
            manifest_id INT NOT NULL,
            amount DECIMAL(10, 2) NOT NULL,
            receipt_file_path VARCHAR(500) NOT NULL,
            status VARCHAR(50) NOT NULL DEFAULT \'pending_validation\',
            submitted_by_id INT NOT NULL,
            validated_by_id INT DEFAULT NULL,
            validated_at DATETIME DEFAULT NULL,
            rejection_reason TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            INDEX idx_payments_edo_manifest (manifest_id),
            INDEX idx_payments_edo_status (status),
            INDEX idx_payments_edo_submitted_by (submitted_by_id),
            INDEX idx_payments_edo_created_at (created_at),
            CONSTRAINT FK_payments_edo_manifest FOREIGN KEY (manifest_id) REFERENCES manifests (id) ON DELETE CASCADE,
            CONSTRAINT FK_payments_edo_submitted_by FOREIGN KEY (submitted_by_id) REFERENCES users (id) ON DELETE RESTRICT,
            CONSTRAINT FK_payments_edo_validated_by FOREIGN KEY (validated_by_id) REFERENCES users (id) ON DELETE SET NULL
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Add payment status constraint
        $this->addSql('ALTER TABLE payments_edo ADD CONSTRAINT chk_payments_edo_status 
            CHECK (status IN (\'pending_validation\', \'verified\', \'rejected\'))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE payments_edo');
    }
}
