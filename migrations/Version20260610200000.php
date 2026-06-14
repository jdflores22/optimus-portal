<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Idempotent migration for shipping line branding columns.
 * Safe when Version20260412130000 was skipped or partially applied.
 */
final class Version20260610200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ensure shipping_lines has logo_path and brand_color columns (idempotent)';
    }

    public function up(Schema $schema): void
    {
        if (!$this->columnExists('shipping_lines', 'logo_path')) {
            $this->addSql('ALTER TABLE shipping_lines ADD logo_path VARCHAR(500) DEFAULT NULL COMMENT \'Path to shipping line logo file\'');
        }

        if (!$this->columnExists('shipping_lines', 'brand_color')) {
            $this->addSql('ALTER TABLE shipping_lines ADD brand_color VARCHAR(7) DEFAULT NULL COMMENT \'Hex color code for branding (e.g., #0066CC)\'');
        }

        if (!$this->indexExists('shipping_lines', 'idx_shipping_lines_logo')) {
            $this->addSql('CREATE INDEX idx_shipping_lines_logo ON shipping_lines (logo_path)');
        }

        if (!$this->indexExists('shipping_lines', 'idx_shipping_lines_brand_color')) {
            $this->addSql('CREATE INDEX idx_shipping_lines_brand_color ON shipping_lines (brand_color)');
        }
    }

    public function down(Schema $schema): void
    {
        if ($this->indexExists('shipping_lines', 'idx_shipping_lines_brand_color')) {
            $this->addSql('DROP INDEX idx_shipping_lines_brand_color ON shipping_lines');
        }

        if ($this->indexExists('shipping_lines', 'idx_shipping_lines_logo')) {
            $this->addSql('DROP INDEX idx_shipping_lines_logo ON shipping_lines');
        }

        if ($this->columnExists('shipping_lines', 'brand_color')) {
            $this->addSql('ALTER TABLE shipping_lines DROP COLUMN brand_color');
        }

        if ($this->columnExists('shipping_lines', 'logo_path')) {
            $this->addSql('ALTER TABLE shipping_lines DROP COLUMN logo_path');
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

    private function indexExists(string $table, string $indexName): bool
    {
        $result = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $indexName]
        );

        return (int) $result > 0;
    }
}
