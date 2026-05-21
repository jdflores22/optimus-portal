<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration for pending_users table - supports email-based role acceptance workflow
 */
final class Version20260330120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create pending_users table for email-based role acceptance workflow';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE pending_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(180) NOT NULL,
            first_name VARCHAR(100) NOT NULL,
            last_name VARCHAR(100) NOT NULL,
            role VARCHAR(50) NOT NULL,
            acceptance_token VARCHAR(64) NOT NULL UNIQUE,
            token_expires_at DATETIME NOT NULL,
            created_by_admin_id INT NOT NULL,
            shipping_line_id INT NULL,
            shipping_line_admin_id INT NULL,
            status VARCHAR(20) NOT NULL DEFAULT "pending",
            created_at DATETIME NOT NULL,
            
            INDEX idx_pending_users_token (acceptance_token),
            INDEX idx_pending_users_email (email),
            INDEX idx_pending_users_admin (created_by_admin_id),
            INDEX idx_pending_users_status (status),
            INDEX idx_pending_users_expires (token_expires_at),
            
            FOREIGN KEY (created_by_admin_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (shipping_line_id) REFERENCES shipping_lines(id) ON DELETE CASCADE,
            FOREIGN KEY (shipping_line_admin_id) REFERENCES users(id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE pending_users');
    }
}