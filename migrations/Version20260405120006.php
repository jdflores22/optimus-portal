<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create electronic_delivery_orders table for eDO documents
 */
final class Version20260405120006 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create electronic_delivery_orders table with unique constraints';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE electronic_delivery_orders (
            id INT AUTO_INCREMENT NOT NULL,
            edo_number VARCHAR(100) NOT NULL UNIQUE,
            manifest_id INT NOT NULL UNIQUE,
            payment_id INT NOT NULL UNIQUE,
            pdf_path VARCHAR(500) NOT NULL,
            digital_signature VARCHAR(500) DEFAULT NULL,
            generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            INDEX idx_edos_edo_number (edo_number),
            INDEX idx_edos_manifest (manifest_id),
            INDEX idx_edos_payment (payment_id),
            CONSTRAINT FK_edos_manifest FOREIGN KEY (manifest_id) REFERENCES manifests (id) ON DELETE CASCADE,
            CONSTRAINT FK_edos_payment FOREIGN KEY (payment_id) REFERENCES payments (id) ON DELETE RESTRICT
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE electronic_delivery_orders');
    }
}
