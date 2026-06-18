<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260617120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add document_verifications table for QR-based document authenticity checks';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE document_verifications (
            id INT AUTO_INCREMENT NOT NULL,
            verification_token VARCHAR(64) NOT NULL,
            document_type VARCHAR(32) NOT NULL,
            subject_type VARCHAR(32) NOT NULL,
            subject_id INT NOT NULL,
            document_number VARCHAR(100) NOT NULL,
            summary JSON DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE INDEX uniq_document_verification_token (verification_token),
            UNIQUE INDEX uniq_document_verification_subject (document_type, subject_type, subject_id),
            INDEX idx_document_verification_token (verification_token),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE document_verifications');
    }
}
