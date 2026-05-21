<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration for notification_metrics table
 * Tracks delivery, open, and failure metrics for push notifications
 */
final class Version20260406120004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create notification_metrics table for tracking push notification analytics';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE notification_metrics (
            id SERIAL PRIMARY KEY,
            notification_id INTEGER NULL,
            user_id INTEGER NOT NULL,
            notification_type VARCHAR(50) NOT NULL,
            sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            delivered_at TIMESTAMP NULL,
            opened_at TIMESTAMP NULL,
            failed_at TIMESTAMP NULL,
            failure_reason TEXT NULL,
            delivery_status VARCHAR(20) NOT NULL DEFAULT \'pending\',
            CONSTRAINT fk_notification_metrics_notification 
                FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE SET NULL,
            CONSTRAINT fk_notification_metrics_user 
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )');
        
        $this->addSql('CREATE INDEX idx_notification_metrics_sent_at ON notification_metrics(sent_at)');
        $this->addSql('CREATE INDEX idx_notification_metrics_notification_type ON notification_metrics(notification_type)');
        $this->addSql('CREATE INDEX idx_notification_metrics_user_id ON notification_metrics(user_id)');
        $this->addSql('CREATE INDEX idx_notification_metrics_delivery_status ON notification_metrics(delivery_status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE notification_metrics');
    }
}
