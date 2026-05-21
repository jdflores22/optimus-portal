<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create container_types and container_sizes tables with seed data
 */
final class Version20260404140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create container_types and container_sizes tables with initial seed data';
    }

    public function up(Schema $schema): void
    {
        // Create container_types table
        $this->addSql('CREATE TABLE container_types (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(100) NOT NULL,
            code VARCHAR(50) NOT NULL,
            description TEXT DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY(id),
            UNIQUE INDEX UNIQ_container_types_code (code),
            INDEX idx_container_types_active (is_active),
            INDEX idx_container_types_code (code)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Create container_sizes table
        $this->addSql('CREATE TABLE container_sizes (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(100) NOT NULL,
            code VARCHAR(50) NOT NULL,
            description TEXT DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY(id),
            UNIQUE INDEX UNIQ_container_sizes_code (code),
            INDEX idx_container_sizes_active (is_active),
            INDEX idx_container_sizes_code (code)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Seed container types
        $this->addSql("INSERT INTO container_types (name, code, description, is_active, created_at, updated_at) VALUES
            ('Dry Container', 'DRY', 'Standard dry cargo container', 1, NOW(), NOW()),
            ('Reefer Container', 'REEFER', 'Refrigerated container for temperature-sensitive cargo', 1, NOW(), NOW()),
            ('Open Top Container', 'OPEN_TOP', 'Container with removable top for oversized cargo', 1, NOW(), NOW()),
            ('Flat Rack Container', 'FLAT_RACK', 'Container with collapsible sides for heavy/oversized cargo', 1, NOW(), NOW()),
            ('Tank Container', 'TANK', 'Container for liquid cargo', 1, NOW(), NOW()),
            ('High Cube Container', 'HIGH_CUBE', 'Extra-height container for voluminous cargo', 1, NOW(), NOW())
        ");

        // Seed container sizes
        $this->addSql("INSERT INTO container_sizes (name, code, description, is_active, created_at, updated_at) VALUES
            ('20 Feet', '20FT', 'Standard 20-foot container (1 TEU)', 1, NOW(), NOW()),
            ('40 Feet', '40FT', 'Standard 40-foot container (2 TEU)', 1, NOW(), NOW()),
            ('40 Feet High Cube', '40HC', '40-foot high cube container (2 TEU)', 1, NOW(), NOW()),
            ('45 Feet', '45FT', 'Extended 45-foot container (2.25 TEU)', 1, NOW(), NOW())
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE container_sizes');
        $this->addSql('DROP TABLE container_types');
    }
}
