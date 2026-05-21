<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration to add batch tracking columns to edo_audit_logs table
 */
final class Version20260420120013 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add batch tracking columns (batchSessionId and batchSequence) to edo_audit_logs table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            ALTER TABLE edo_audit_logs 
            ADD batch_session_id VARCHAR(36) DEFAULT NULL,
            ADD batch_sequence INT DEFAULT NULL
        ');

        // Add index for batch session ID for efficient querying
        $this->addSql('
            CREATE INDEX idx_edo_audit_batch_session ON edo_audit_logs (batch_session_id)
        ');
    }

    public function down(Schema $schema): void
    {
        // Drop index first
        $this->addSql('DROP INDEX idx_edo_audit_batch_session ON edo_audit_logs');

        // Drop columns
        $this->addSql('
            ALTER TABLE edo_audit_logs 
            DROP batch_session_id,
            DROP batch_sequence
        ');
    }
}
