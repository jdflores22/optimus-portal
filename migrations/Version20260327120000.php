<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add shipping line terminal allocations for SHIPPING_LINES_ADMIN users
 */
final class Version20260327120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add shipping line terminal allocations for SHIPPING_LINES_ADMIN users';
    }

    public function up(Schema $schema): void
    {
        // Create shipping_line_terminal_allocations table
        $this->addSql('CREATE TABLE shipping_line_terminal_allocations (
            id INT AUTO_INCREMENT NOT NULL,
            staff_user_id INT NOT NULL,
            terminal_id INT NOT NULL,
            allocated_capacity INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY(id),
            UNIQUE KEY unique_staff_terminal (staff_user_id, terminal_id),
            INDEX idx_staff_user (staff_user_id),
            INDEX idx_terminal (terminal_id),
            CONSTRAINT FK_allocation_staff_user FOREIGN KEY (staff_user_id) REFERENCES staff_users (id) ON DELETE CASCADE,
            CONSTRAINT FK_allocation_terminal FOREIGN KEY (terminal_id) REFERENCES terminals (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE shipping_line_terminal_allocations');
    }
}