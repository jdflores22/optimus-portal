<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create workflow_state_history table for audit trail
 */
final class Version20260405120007 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create workflow_state_history table for tracking state transitions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE workflow_state_history (
            id INT AUTO_INCREMENT NOT NULL,
            manifest_id INT NOT NULL,
            from_state VARCHAR(50) DEFAULT NULL,
            to_state VARCHAR(50) NOT NULL,
            actor_id INT NOT NULL,
            actor_role VARCHAR(50) NOT NULL,
            transition_reason TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            INDEX idx_workflow_history_manifest (manifest_id),
            INDEX idx_workflow_history_created_at (created_at),
            CONSTRAINT FK_workflow_history_manifest FOREIGN KEY (manifest_id) REFERENCES manifests (id) ON DELETE CASCADE,
            CONSTRAINT FK_workflow_history_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE RESTRICT
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE workflow_state_history');
    }
}
