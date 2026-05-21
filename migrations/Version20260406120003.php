<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create queued_notifications table for Do Not Disturb mode
 */
final class Version20260406120003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create queued_notifications table for storing notifications during Do Not Disturb mode';
    }

    public function up(Schema $schema): void
    {
        // Create queued_notifications table
        $this->addSql('CREATE TABLE queued_notifications (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            type VARCHAR(100) NOT NULL,
            metadata JSON NOT NULL,
            queued_at DATETIME NOT NULL,
            PRIMARY KEY(id),
            INDEX idx_queued_notifications_user_queued (user_id, queued_at),
            CONSTRAINT FK_queued_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        // Drop queued_notifications table
        $this->addSql('DROP TABLE queued_notifications');
    }
}
