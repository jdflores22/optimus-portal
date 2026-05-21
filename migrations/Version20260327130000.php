<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration for shipping line configuration tables
 */
final class Version20260327130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create shipping line configuration and role permission tables';
    }

    public function up(Schema $schema): void
    {
        // Create shipping_line_configurations table
        $this->addSql('CREATE TABLE shipping_line_configurations (
            id INT AUTO_INCREMENT NOT NULL,
            shipping_line_id INT NOT NULL,
            config_key VARCHAR(255) NOT NULL,
            config_value JSON NOT NULL,
            description VARCHAR(500) DEFAULT NULL,
            is_active TINYINT(1) DEFAULT 1 NOT NULL,
            created_by_id INT NOT NULL,
            updated_by_id INT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_shipping_line_configurations_shipping_line (shipping_line_id),
            INDEX idx_shipping_line_configurations_key (config_key),
            INDEX idx_shipping_line_configurations_created_at (created_at),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Create role_permission_configurations table
        $this->addSql('CREATE TABLE role_permission_configurations (
            id INT AUTO_INCREMENT NOT NULL,
            shipping_line_id INT NOT NULL,
            role VARCHAR(255) NOT NULL,
            permissions JSON NOT NULL,
            restrictions JSON DEFAULT NULL,
            is_active TINYINT(1) DEFAULT 1 NOT NULL,
            inherit_from_parent TINYINT(1) DEFAULT 0 NOT NULL,
            created_by_id INT NOT NULL,
            updated_by_id INT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_role_permissions_shipping_line (shipping_line_id),
            INDEX idx_role_permissions_role (role),
            INDEX idx_role_permissions_created_at (created_at),
            UNIQUE INDEX UNIQ_role_permissions_shipping_line_role (shipping_line_id, role),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Create configuration_history table
        $this->addSql('CREATE TABLE configuration_history (
            id INT AUTO_INCREMENT NOT NULL,
            shipping_line_id INT NOT NULL,
            config_type VARCHAR(100) NOT NULL,
            config_key VARCHAR(255) NOT NULL,
            old_value JSON DEFAULT NULL,
            new_value JSON NOT NULL,
            action VARCHAR(50) NOT NULL,
            changed_by_id INT NOT NULL,
            reason VARCHAR(500) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_config_history_shipping_line (shipping_line_id),
            INDEX idx_config_history_type (config_type),
            INDEX idx_config_history_created_at (created_at),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Add foreign key constraints
        $this->addSql('ALTER TABLE shipping_line_configurations 
            ADD CONSTRAINT FK_shipping_line_configurations_shipping_line 
            FOREIGN KEY (shipping_line_id) REFERENCES shipping_lines (id) ON DELETE CASCADE');
        
        $this->addSql('ALTER TABLE shipping_line_configurations 
            ADD CONSTRAINT FK_shipping_line_configurations_created_by 
            FOREIGN KEY (created_by_id) REFERENCES users (id)');
        
        $this->addSql('ALTER TABLE shipping_line_configurations 
            ADD CONSTRAINT FK_shipping_line_configurations_updated_by 
            FOREIGN KEY (updated_by_id) REFERENCES users (id)');

        $this->addSql('ALTER TABLE role_permission_configurations 
            ADD CONSTRAINT FK_role_permission_configurations_shipping_line 
            FOREIGN KEY (shipping_line_id) REFERENCES shipping_lines (id) ON DELETE CASCADE');
        
        $this->addSql('ALTER TABLE role_permission_configurations 
            ADD CONSTRAINT FK_role_permission_configurations_created_by 
            FOREIGN KEY (created_by_id) REFERENCES users (id)');
        
        $this->addSql('ALTER TABLE role_permission_configurations 
            ADD CONSTRAINT FK_role_permission_configurations_updated_by 
            FOREIGN KEY (updated_by_id) REFERENCES users (id)');

        $this->addSql('ALTER TABLE configuration_history 
            ADD CONSTRAINT FK_configuration_history_shipping_line 
            FOREIGN KEY (shipping_line_id) REFERENCES shipping_lines (id) ON DELETE CASCADE');
        
        $this->addSql('ALTER TABLE configuration_history 
            ADD CONSTRAINT FK_configuration_history_changed_by 
            FOREIGN KEY (changed_by_id) REFERENCES users (id)');
    }

    public function down(Schema $schema): void
    {
        // Drop foreign key constraints first
        $this->addSql('ALTER TABLE shipping_line_configurations DROP FOREIGN KEY FK_shipping_line_configurations_shipping_line');
        $this->addSql('ALTER TABLE shipping_line_configurations DROP FOREIGN KEY FK_shipping_line_configurations_created_by');
        $this->addSql('ALTER TABLE shipping_line_configurations DROP FOREIGN KEY FK_shipping_line_configurations_updated_by');
        
        $this->addSql('ALTER TABLE role_permission_configurations DROP FOREIGN KEY FK_role_permission_configurations_shipping_line');
        $this->addSql('ALTER TABLE role_permission_configurations DROP FOREIGN KEY FK_role_permission_configurations_created_by');
        $this->addSql('ALTER TABLE role_permission_configurations DROP FOREIGN KEY FK_role_permission_configurations_updated_by');
        
        $this->addSql('ALTER TABLE configuration_history DROP FOREIGN KEY FK_configuration_history_shipping_line');
        $this->addSql('ALTER TABLE configuration_history DROP FOREIGN KEY FK_configuration_history_changed_by');

        // Drop tables
        $this->addSql('DROP TABLE configuration_history');
        $this->addSql('DROP TABLE role_permission_configurations');
        $this->addSql('DROP TABLE shipping_line_configurations');
    }
}