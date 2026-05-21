<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration for notification delivery logging and monitoring
 * Implements Requirements 8.1, 8.2, 8.3, 8.4
 */
final class Version20260330170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create notification_delivery_logs table for notification monitoring and dashboard';
    }

    public function up(Schema $schema): void
    {
        // Create notification_delivery_logs table
        $this->addSql('CREATE TABLE notification_delivery_logs (
            id INT AUTO_INCREMENT NOT NULL,
            container_id INT NOT NULL,
            recipient_id INT NOT NULL,
            notification_type VARCHAR(50) NOT NULL,
            delivery_status VARCHAR(20) NOT NULL,
            channel VARCHAR(20) NOT NULL,
            created_at DATETIME NOT NULL,
            delivered_at DATETIME DEFAULT NULL,
            attempt_count INT DEFAULT 0 NOT NULL,
            last_attempt_at DATETIME DEFAULT NULL,
            error_message TEXT DEFAULT NULL,
            metadata JSON DEFAULT NULL,
            INDEX idx_container (container_id),
            INDEX idx_notification_type (notification_type),
            INDEX idx_delivery_status (delivery_status),
            INDEX idx_created_at (created_at),
            INDEX IDX_NOTIFICATION_DELIVERY_CONTAINER (container_id),
            INDEX IDX_NOTIFICATION_DELIVERY_RECIPIENT (recipient_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Add foreign key constraints
        $this->addSql('ALTER TABLE notification_delivery_logs 
            ADD CONSTRAINT FK_NOTIFICATION_DELIVERY_CONTAINER 
            FOREIGN KEY (container_id) REFERENCES containers (id)');
        
        $this->addSql('ALTER TABLE notification_delivery_logs 
            ADD CONSTRAINT FK_NOTIFICATION_DELIVERY_RECIPIENT 
            FOREIGN KEY (recipient_id) REFERENCES users (id)');
    }

    public function down(Schema $schema): void
    {
        // Drop foreign key constraints
        $this->addSql('ALTER TABLE notification_delivery_logs 
            DROP FOREIGN KEY FK_NOTIFICATION_DELIVERY_CONTAINER');
        
        $this->addSql('ALTER TABLE notification_delivery_logs 
            DROP FOREIGN KEY FK_NOTIFICATION_DELIVERY_RECIPIENT');

        // Drop table
        $this->addSql('DROP TABLE notification_delivery_logs');
    }
}
