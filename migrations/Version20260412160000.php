<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Multi-Tenant Shipping Line - Task 1.4: Create User Preferences Table
 * 
 * Creates user_shipping_line_preferences table to store user's last selected
 * shipping line for automatic context restoration on login.
 */
final class Version20260412160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create user_shipping_line_preferences table for storing user shipping line context';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE IF NOT EXISTS user_shipping_line_preferences (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                last_selected_shipping_line_id INT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                CONSTRAINT fk_user_preferences_user 
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_user_preferences_shipping_line 
                    FOREIGN KEY (last_selected_shipping_line_id) REFERENCES shipping_lines(id) ON DELETE SET NULL,
                UNIQUE KEY unique_user_preference (user_id),
                INDEX idx_user_preferences_user (user_id),
                INDEX idx_user_preferences_shipping_line (last_selected_shipping_line_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS user_shipping_line_preferences');
    }
}
