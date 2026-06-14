<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Finalize NOA location rename: cy_location → port_location (drop legacy column).
 */
final class Version20260610230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop legacy noa.cy_location column after consolidating data into port_location';
    }

    public function up(Schema $schema): void
    {
        if ($this->columnExists('noa', 'cy_location') && $this->columnExists('noa', 'port_location')) {
            $this->addSql("UPDATE noa SET port_location = cy_location WHERE (port_location IS NULL OR port_location = '') AND cy_location IS NOT NULL AND cy_location != ''");
            $this->addSql('ALTER TABLE noa DROP COLUMN cy_location');
        } elseif ($this->columnExists('noa', 'cy_location') && !$this->columnExists('noa', 'port_location')) {
            $this->addSql('ALTER TABLE noa CHANGE cy_location port_location VARCHAR(100) DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$this->columnExists('noa', 'cy_location') && $this->columnExists('noa', 'port_location')) {
            $this->addSql('ALTER TABLE noa ADD cy_location VARCHAR(100) DEFAULT NULL');
            $this->addSql('UPDATE noa SET cy_location = port_location WHERE cy_location IS NULL AND port_location IS NOT NULL');
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        $result = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );

        return (int) $result > 0;
    }
}
