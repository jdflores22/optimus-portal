<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create edo_versions table for tracking eDO version history
 */
final class Version20260527120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create edo_versions table for tracking eDO PDF version history';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE edo_versions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                edo_id INT NOT NULL,
                version_number INT NOT NULL,
                pdf_path VARCHAR(500) NOT NULL,
                edo_number VARCHAR(50) NOT NULL,
                status VARCHAR(50) NOT NULL,
                created_at DATETIME NOT NULL,
                created_by_id INT DEFAULT NULL,
                expires_at DATETIME DEFAULT NULL,
                cy_location VARCHAR(255) DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                is_current BOOLEAN DEFAULT 0,
                CONSTRAINT FK_edo_versions_edo 
                    FOREIGN KEY (edo_id) REFERENCES electronic_delivery_orders(id) ON DELETE CASCADE,
                CONSTRAINT FK_edo_versions_created_by 
                    FOREIGN KEY (created_by_id) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_edo_versions_edo_id (edo_id),
                INDEX idx_edo_versions_current (is_current),
                INDEX idx_edo_versions_created_at (created_at),
                UNIQUE KEY unique_edo_version (edo_id, version_number)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS edo_versions');
    }
}
