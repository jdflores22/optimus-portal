<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add port_location to NOA for laden container discharge planning.
 * Migrates existing cy_location values; cy_location becomes optional (set later for empty CY delivery).
 */
final class Version20260610210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add port_location to noa table and migrate existing cy_location data';
    }

    public function up(Schema $schema): void
    {
        if (!$this->columnExists('noa', 'port_location')) {
            $this->addSql('ALTER TABLE noa ADD port_location VARCHAR(100) DEFAULT NULL');
        }

        $this->addSql('UPDATE noa SET port_location = cy_location WHERE port_location IS NULL AND cy_location IS NOT NULL');

        $this->addSql('ALTER TABLE noa MODIFY cy_location VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE noa SET cy_location = port_location WHERE cy_location IS NULL AND port_location IS NOT NULL');
        $this->addSql('ALTER TABLE noa MODIFY cy_location VARCHAR(100) NOT NULL');

        if ($this->columnExists('noa', 'port_location')) {
            $this->addSql('ALTER TABLE noa DROP COLUMN port_location');
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
