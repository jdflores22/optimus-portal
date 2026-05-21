<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create noa_documents table for Notice of Arrival documents
 */
final class Version20260405120005 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create noa_documents table with JSONB for vessel_info';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE noa_documents (
            id INT AUTO_INCREMENT NOT NULL,
            manifest_id INT NOT NULL UNIQUE,
            noa_number VARCHAR(100) NOT NULL UNIQUE,
            arrival_date DATETIME NOT NULL,
            vessel_info JSON NOT NULL,
            pdf_path VARCHAR(500) NOT NULL,
            generated_by_id INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            INDEX idx_noa_documents_manifest (manifest_id),
            INDEX idx_noa_documents_noa_number (noa_number),
            CONSTRAINT FK_noa_documents_manifest FOREIGN KEY (manifest_id) REFERENCES manifests (id) ON DELETE CASCADE,
            CONSTRAINT FK_noa_documents_generated_by FOREIGN KEY (generated_by_id) REFERENCES users (id) ON DELETE RESTRICT
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE noa_documents');
    }
}
