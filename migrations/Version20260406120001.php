<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create push_subscriptions table for Web Push notification subscriptions
 */
final class Version20260406120001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create push_subscriptions table for storing Web Push notification subscriptions';
    }

    public function up(Schema $schema): void
    {
        // Create push_subscriptions table
        $this->addSql('CREATE TABLE push_subscriptions (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            endpoint TEXT NOT NULL,
            p256dh_key VARCHAR(255) NOT NULL,
            auth_key VARCHAR(255) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            last_used_at DATETIME DEFAULT NULL,
            user_agent VARCHAR(100) DEFAULT NULL,
            PRIMARY KEY(id),
            UNIQUE INDEX unique_endpoint (endpoint(255)),
            INDEX idx_push_subscriptions_user_active (user_id, is_active),
            INDEX idx_push_subscriptions_endpoint (endpoint(255)),
            CONSTRAINT FK_push_subscriptions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        // Drop push_subscriptions table
        $this->addSql('DROP TABLE push_subscriptions');
    }
}
