<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create manifests table for manifest payment and NOA workflow
 */
final class Version20260405120002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create manifests table with workflow state management and relationships to users';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE manifests (
            id INT AUTO_INCREMENT NOT NULL,
            manifest_number VARCHAR(100) NOT NULL UNIQUE,
            consignee_id INT DEFAULT NULL,
            broker_id INT DEFAULT NULL,
            vessel_name VARCHAR(255) DEFAULT NULL,
            voyage_number VARCHAR(100) DEFAULT NULL,
            arrival_date DATETIME DEFAULT NULL,
            bl_number VARCHAR(100) DEFAULT NULL,
            bl_file_path VARCHAR(500) DEFAULT NULL,
            workflow_state VARCHAR(50) NOT NULL DEFAULT \'pending_payment\',
            created_by_id INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            INDEX idx_manifests_manifest_number (manifest_number),
            INDEX idx_manifests_consignee (consignee_id),
            INDEX idx_manifests_broker (broker_id),
            INDEX idx_manifests_workflow_state (workflow_state),
            INDEX idx_manifests_created_at (created_at),
            CONSTRAINT FK_manifests_consignee FOREIGN KEY (consignee_id) REFERENCES users (id) ON DELETE SET NULL,
            CONSTRAINT FK_manifests_broker FOREIGN KEY (broker_id) REFERENCES users (id) ON DELETE SET NULL,
            CONSTRAINT FK_manifests_created_by FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE RESTRICT
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Add workflow state constraint
        $this->addSql('ALTER TABLE manifests ADD CONSTRAINT chk_workflow_state 
            CHECK (workflow_state IN (
                \'pending_payment\', 
                \'payment_verified\', 
                \'noa_generated\', 
                \'bl_uploaded\', 
                \'billing_generated\', 
                \'payment_submitted\', 
                \'edo_generated\'
            ))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE manifests');
    }
}
