<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create billings table for freight and THC charges
 */
final class Version20260405120004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create billings table with JSONB for additional_charges and positive amounts constraint';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE billings (
            id INT AUTO_INCREMENT NOT NULL,
            manifest_id INT NOT NULL UNIQUE,
            freight_charges DECIMAL(10, 2) NOT NULL,
            thc_charges DECIMAL(10, 2) NOT NULL,
            additional_charges JSON DEFAULT NULL,
            total_amount DECIMAL(10, 2) NOT NULL,
            pdf_path VARCHAR(500) DEFAULT NULL,
            generated_by_id INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            INDEX idx_billings_manifest (manifest_id),
            INDEX idx_billings_created_at (created_at),
            CONSTRAINT FK_billings_manifest FOREIGN KEY (manifest_id) REFERENCES manifests (id) ON DELETE CASCADE,
            CONSTRAINT FK_billings_generated_by FOREIGN KEY (generated_by_id) REFERENCES users (id) ON DELETE RESTRICT
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Add positive amounts constraint
        $this->addSql('ALTER TABLE billings ADD CONSTRAINT chk_positive_amounts 
            CHECK (freight_charges >= 0 AND thc_charges >= 0 AND total_amount >= 0)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE billings');
    }
}
