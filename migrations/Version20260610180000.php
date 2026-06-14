<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Idempotent migration for user deactivation fields required by User entity.
 * Safe to run when Version20260414130000 was skipped or partially applied.
 */
final class Version20260610180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ensure users table has is_active and deactivation columns (idempotent)';
    }

    public function up(Schema $schema): void
    {
        if (!$this->columnExists('users', 'is_active')) {
            $this->addSql('ALTER TABLE users ADD is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER status');
            $this->addSql('CREATE INDEX IDX_users_active ON users (is_active)');
        }

        if (!$this->columnExists('users', 'deactivated_at')) {
            $this->addSql('ALTER TABLE users ADD deactivated_at DATETIME DEFAULT NULL AFTER is_active');
        }

        if (!$this->columnExists('users', 'deactivated_by_id')) {
            $this->addSql('ALTER TABLE users ADD deactivated_by_id INT DEFAULT NULL AFTER deactivated_at');
        }

        if (!$this->columnExists('users', 'deactivation_reason')) {
            $this->addSql('ALTER TABLE users ADD deactivation_reason TEXT DEFAULT NULL AFTER deactivated_by_id');
        }

        if (!$this->columnExists('users', 'suspension_attachments')) {
            $this->addSql('ALTER TABLE users ADD suspension_attachments JSON DEFAULT NULL AFTER deactivation_reason');
        }

        if (!$this->foreignKeyExists('users', 'FK_users_deactivated_by')) {
            $this->addSql('ALTER TABLE users ADD CONSTRAINT FK_users_deactivated_by FOREIGN KEY (deactivated_by_id) REFERENCES users (id) ON DELETE SET NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if ($this->foreignKeyExists('users', 'FK_users_deactivated_by')) {
            $this->addSql('ALTER TABLE users DROP FOREIGN KEY FK_users_deactivated_by');
        }

        if ($this->indexExists('users', 'IDX_users_active')) {
            $this->addSql('DROP INDEX IDX_users_active ON users');
        }

        foreach (['suspension_attachments', 'deactivation_reason', 'deactivated_by_id', 'deactivated_at', 'is_active'] as $column) {
            if ($this->columnExists('users', $column)) {
                $this->addSql(sprintf('ALTER TABLE users DROP COLUMN %s', $column));
            }
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        $result = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );

        return (int) $result > 0;
    }

    private function foreignKeyExists(string $table, string $constraintName): bool
    {
        $result = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$table, $constraintName, 'FOREIGN KEY']
        );

        return (int) $result > 0;
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $result = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $indexName]
        );

        return (int) $result > 0;
    }
}
