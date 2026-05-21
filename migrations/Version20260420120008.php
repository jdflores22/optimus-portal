<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration for System Configuration and Configuration History tables
 * Task 20.1: Create system configuration for eDO settings
 */
final class Version20260420120008 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create system_configurations and configuration_history tables for eDO workflow settings';
    }

    public function up(Schema $schema): void
    {
        // Create system_configurations table
        $this->addSql('CREATE TABLE system_configurations (
            id INT AUTO_INCREMENT NOT NULL,
            updated_by_id INT DEFAULT NULL,
            config_key VARCHAR(100) NOT NULL,
            config_value TEXT NOT NULL,
            value_type VARCHAR(50) NOT NULL,
            description TEXT DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE INDEX UNIQ_config_key (config_key),
            INDEX idx_config_key (config_key),
            INDEX idx_is_active (is_active),
            INDEX IDX_updated_by (updated_by_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE system_configurations 
            ADD CONSTRAINT FK_system_config_updated_by 
            FOREIGN KEY (updated_by_id) REFERENCES users (id) ON DELETE SET NULL');

        // Create configuration_history table
        $this->addSql('CREATE TABLE configuration_history (
            id INT AUTO_INCREMENT NOT NULL,
            changed_by_id INT NOT NULL,
            config_key VARCHAR(100) NOT NULL,
            old_value TEXT NOT NULL,
            new_value TEXT NOT NULL,
            changed_at DATETIME NOT NULL,
            change_reason TEXT DEFAULT NULL,
            INDEX idx_history_config_key (config_key),
            INDEX idx_history_changed_at (changed_at),
            INDEX IDX_changed_by (changed_by_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE configuration_history 
            ADD CONSTRAINT FK_config_history_changed_by 
            FOREIGN KEY (changed_by_id) REFERENCES users (id) ON DELETE CASCADE');

        // Insert default eDO configuration values
        $this->addSql("INSERT INTO system_configurations 
            (config_key, config_value, value_type, description, is_active, created_at, updated_at) 
            VALUES 
            ('edo.validity_period_days', '30', 'integer', 'Number of days an eDO remains valid after generation', 1, NOW(), NOW()),
            ('edo.expired_per_day_rate', '50.00', 'float', 'Per-day rate charged for expired eDO days', 1, NOW(), NOW()),
            ('cy.locations', '{\"CY-A\":1000,\"CY-B\":1500,\"CY-C\":2000,\"CY-NORTH\":3000,\"CY-SOUTH\":2500}', 'json', 'Container Yard locations with TEU capacities', 1, NOW(), NOW())
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE configuration_history DROP FOREIGN KEY FK_config_history_changed_by');
        $this->addSql('ALTER TABLE system_configurations DROP FOREIGN KEY FK_system_config_updated_by');
        $this->addSql('DROP TABLE configuration_history');
        $this->addSql('DROP TABLE system_configurations');
    }
}
