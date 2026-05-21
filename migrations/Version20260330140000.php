<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Container Dwell Time Management - Database schema setup and migrations
 */
final class Version20260330140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add dwell time management entities and extend Container entity with dwell time tracking fields';
    }

    public function up(Schema $schema): void
    {
        // Create dwell_time_configuration table
        $this->addSql('CREATE TABLE dwell_time_configuration (
            id INT AUTO_INCREMENT NOT NULL, 
            notification_threshold_days INT DEFAULT 60 NOT NULL, 
            automatic_return_threshold_days INT DEFAULT 90 NOT NULL, 
            timezone VARCHAR(50) DEFAULT \'UTC\' NOT NULL, 
            enable_automatic_returns TINYINT(1) DEFAULT 1 NOT NULL, 
            enable_notifications TINYINT(1) DEFAULT 1 NOT NULL, 
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Create dwell_time_events table
        $this->addSql('CREATE TABLE dwell_time_events (
            id INT AUTO_INCREMENT NOT NULL, 
            container_id INT NOT NULL, 
            triggered_by_id INT DEFAULT NULL, 
            event_type VARCHAR(255) NOT NULL, 
            event_date DATETIME NOT NULL, 
            dwell_time_at_event INT DEFAULT NULL, 
            reason LONGTEXT DEFAULT NULL, 
            metadata JSON DEFAULT NULL, 
            INDEX IDX_DTE_CONTAINER (container_id), 
            INDEX IDX_DTE_USER (triggered_by_id), 
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Add dwell time fields to containers table
        $this->addSql('ALTER TABLE containers ADD terminal_arrival_date DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE containers ADD current_dwell_time INT DEFAULT NULL');
        $this->addSql('ALTER TABLE containers ADD last_dwell_time_calculation DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE containers ADD dwell_time_paused_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE containers ADD total_paused_days INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE containers ADD next_notification_date DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE containers ADD automatic_return_date DATETIME DEFAULT NULL');

        // Update ContainerStatus enum to include ALERT status
        $this->addSql('ALTER TABLE containers MODIFY status VARCHAR(255) NOT NULL');

        // Add foreign key constraints
        $this->addSql('ALTER TABLE dwell_time_events ADD CONSTRAINT FK_DTE_CONTAINER FOREIGN KEY (container_id) REFERENCES containers (id)');
        $this->addSql('ALTER TABLE dwell_time_events ADD CONSTRAINT FK_DTE_USER FOREIGN KEY (triggered_by_id) REFERENCES users (id)');

        // Insert default configuration
        $this->addSql('INSERT INTO dwell_time_configuration (notification_threshold_days, automatic_return_threshold_days, timezone, enable_automatic_returns, enable_notifications) VALUES (60, 90, \'UTC\', 1, 1)');
    }

    public function down(Schema $schema): void
    {
        // Remove foreign key constraints
        $this->addSql('ALTER TABLE dwell_time_events DROP FOREIGN KEY FK_DTE_CONTAINER');
        $this->addSql('ALTER TABLE dwell_time_events DROP FOREIGN KEY FK_DTE_USER');

        // Remove dwell time fields from containers table
        $this->addSql('ALTER TABLE containers DROP terminal_arrival_date');
        $this->addSql('ALTER TABLE containers DROP current_dwell_time');
        $this->addSql('ALTER TABLE containers DROP last_dwell_time_calculation');
        $this->addSql('ALTER TABLE containers DROP dwell_time_paused_at');
        $this->addSql('ALTER TABLE containers DROP total_paused_days');
        $this->addSql('ALTER TABLE containers DROP next_notification_date');
        $this->addSql('ALTER TABLE containers DROP automatic_return_date');

        // Drop tables
        $this->addSql('DROP TABLE dwell_time_events');
        $this->addSql('DROP TABLE dwell_time_configuration');
    }
}