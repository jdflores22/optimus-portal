<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration for edo_generation_sessions table
 * Creates table to track batch eDO generation sessions with progress monitoring
 */
final class Version20260420120011 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create edo_generation_sessions table for batch eDO generation tracking';
    }

    public function up(Schema $schema): void
    {
        // Create edo_generation_sessions table
        $this->addSql('
            CREATE TABLE edo_generation_sessions (
                id INT AUTO_INCREMENT NOT NULL,
                session_id VARCHAR(36) NOT NULL,
                manifest_id INT NOT NULL,
                initiated_by_id INT NOT NULL,
                status VARCHAR(20) NOT NULL,
                total_containers INT NOT NULL,
                completed_containers INT DEFAULT 0 NOT NULL,
                failed_containers INT DEFAULT 0 NOT NULL,
                current_container VARCHAR(50) DEFAULT NULL,
                expiration_date DATETIME NOT NULL,
                failures JSON DEFAULT NULL,
                started_at DATETIME NOT NULL,
                completed_at DATETIME DEFAULT NULL,
                cancelled_at DATETIME DEFAULT NULL,
                cancelled_by_id INT DEFAULT NULL,
                UNIQUE INDEX UNIQ_EDO_GEN_SESSION_ID (session_id),
                INDEX idx_session_id (session_id),
                INDEX idx_status (status),
                INDEX IDX_EDO_GEN_MANIFEST (manifest_id),
                INDEX IDX_EDO_GEN_INITIATED_BY (initiated_by_id),
                INDEX IDX_EDO_GEN_CANCELLED_BY (cancelled_by_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');

        // Add foreign key constraints
        $this->addSql('
            ALTER TABLE edo_generation_sessions 
            ADD CONSTRAINT FK_EDO_GEN_MANIFEST 
            FOREIGN KEY (manifest_id) 
            REFERENCES manifests (id) 
            ON DELETE CASCADE
        ');

        $this->addSql('
            ALTER TABLE edo_generation_sessions 
            ADD CONSTRAINT FK_EDO_GEN_INITIATED_BY 
            FOREIGN KEY (initiated_by_id) 
            REFERENCES users (id)
        ');

        $this->addSql('
            ALTER TABLE edo_generation_sessions 
            ADD CONSTRAINT FK_EDO_GEN_CANCELLED_BY 
            FOREIGN KEY (cancelled_by_id) 
            REFERENCES users (id)
        ');
    }

    public function down(Schema $schema): void
    {
        // Drop foreign key constraints first
        $this->addSql('ALTER TABLE edo_generation_sessions DROP FOREIGN KEY FK_EDO_GEN_MANIFEST');
        $this->addSql('ALTER TABLE edo_generation_sessions DROP FOREIGN KEY FK_EDO_GEN_INITIATED_BY');
        $this->addSql('ALTER TABLE edo_generation_sessions DROP FOREIGN KEY FK_EDO_GEN_CANCELLED_BY');

        // Drop the table
        $this->addSql('DROP TABLE edo_generation_sessions');
    }
}
