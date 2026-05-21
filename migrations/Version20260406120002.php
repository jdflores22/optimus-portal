<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create notification_preferences table for user notification settings
 */
final class Version20260406120002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create notification_preferences table for storing user notification preferences';
    }

    public function up(Schema $schema): void
    {
        // Create notification_preferences table
        $this->addSql('CREATE TABLE notification_preferences (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            enabled_types JSON NOT NULL,
            do_not_disturb_enabled TINYINT(1) NOT NULL DEFAULT 0,
            do_not_disturb_start TIME DEFAULT NULL,
            do_not_disturb_end TIME DEFAULT NULL,
            PRIMARY KEY(id),
            UNIQUE INDEX unique_user_preferences (user_id),
            CONSTRAINT FK_notification_preferences_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        // Drop notification_preferences table
        $this->addSql('DROP TABLE notification_preferences');
    }
}
