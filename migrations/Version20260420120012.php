<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration to add document_type and document_number fields to edo_generation_sessions table
 * Supports multi-document batch eDO generation (NOA, Manifest, BL)
 */
final class Version20260420120012 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add document_type and document_number columns to edo_generation_sessions table for multi-document support';
    }

    public function up(Schema $schema): void
    {
        // Add document_type column with default value 'manifest'
        $this->addSql("ALTER TABLE edo_generation_sessions ADD COLUMN document_type VARCHAR(20) NOT NULL DEFAULT 'manifest'");
        
        // Add document_number column (nullable)
        $this->addSql("ALTER TABLE edo_generation_sessions ADD COLUMN document_number VARCHAR(100) DEFAULT NULL");
        
        // Add index for document_type for faster queries
        $this->addSql("CREATE INDEX idx_document_type ON edo_generation_sessions(document_type)");
    }

    public function down(Schema $schema): void
    {
        // Drop index first
        $this->addSql("DROP INDEX idx_document_type ON edo_generation_sessions");
        
        // Drop columns
        $this->addSql("ALTER TABLE edo_generation_sessions DROP COLUMN document_number");
        $this->addSql("ALTER TABLE edo_generation_sessions DROP COLUMN document_type");
    }
}
