<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Broker-Consignee Relationship Management - Update Existing Tables
 * Updates: users table (deactivation fields), manifests table (archive and transfer fields)
 */
final class Version20260414130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Update users and manifests tables for broker-consignee relationship management';
    }

    public function up(Schema $schema): void
    {
        // Update users table - add deactivation fields
        $this->addSql('ALTER TABLE users 
            ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER status');
        
        $this->addSql('ALTER TABLE users 
            ADD COLUMN deactivated_at DATETIME DEFAULT NULL AFTER is_active');
        
        $this->addSql('ALTER TABLE users 
            ADD COLUMN deactivated_by_id INT DEFAULT NULL AFTER deactivated_at');
        
        $this->addSql('ALTER TABLE users 
            ADD COLUMN deactivation_reason TEXT DEFAULT NULL AFTER deactivated_by_id');
        
        // Add foreign key for deactivated_by
        $this->addSql('ALTER TABLE users 
            ADD CONSTRAINT FK_users_deactivated_by 
            FOREIGN KEY (deactivated_by_id) REFERENCES users (id) ON DELETE SET NULL');
        
        // Add index for is_active
        $this->addSql('CREATE INDEX IDX_users_active ON users (is_active)');

        // Update manifests table - add archive and transfer fields
        $this->addSql('ALTER TABLE manifests 
            ADD COLUMN archived_for_broker TINYINT(1) NOT NULL DEFAULT 0 AFTER workflow_state');
        
        $this->addSql('ALTER TABLE manifests 
            ADD COLUMN completed_at DATETIME DEFAULT NULL AFTER archived_for_broker');
        
        $this->addSql('ALTER TABLE manifests 
            ADD COLUMN completed_by_id INT DEFAULT NULL AFTER completed_at');
        
        $this->addSql('ALTER TABLE manifests 
            ADD COLUMN previous_broker_id INT DEFAULT NULL AFTER completed_by_id');
        
        $this->addSql('ALTER TABLE manifests 
            ADD COLUMN transferred_at DATETIME DEFAULT NULL AFTER previous_broker_id');
        
        $this->addSql('ALTER TABLE manifests 
            ADD COLUMN broker_inactive_since DATETIME DEFAULT NULL AFTER transferred_at');
        
        // Add foreign keys for manifests
        $this->addSql('ALTER TABLE manifests 
            ADD CONSTRAINT FK_manifests_completed_by 
            FOREIGN KEY (completed_by_id) REFERENCES users (id) ON DELETE SET NULL');
        
        $this->addSql('ALTER TABLE manifests 
            ADD CONSTRAINT FK_manifests_previous_broker 
            FOREIGN KEY (previous_broker_id) REFERENCES users (id) ON DELETE SET NULL');
        
        // Add indexes for manifests
        $this->addSql('CREATE INDEX IDX_manifests_archived ON manifests (archived_for_broker)');
        $this->addSql('CREATE INDEX IDX_manifests_broker_status ON manifests (broker_id, workflow_state, archived_for_broker)');
        $this->addSql('CREATE INDEX IDX_manifests_completed_by ON manifests (completed_by_id)');
        $this->addSql('CREATE INDEX IDX_manifests_previous_broker ON manifests (previous_broker_id)');
    }

    public function down(Schema $schema): void
    {
        // Drop indexes first
        $this->addSql('DROP INDEX IDX_manifests_previous_broker ON manifests');
        $this->addSql('DROP INDEX IDX_manifests_completed_by ON manifests');
        $this->addSql('DROP INDEX IDX_manifests_broker_status ON manifests');
        $this->addSql('DROP INDEX IDX_manifests_archived ON manifests');
        $this->addSql('DROP INDEX IDX_users_active ON users');
        
        // Drop foreign keys
        $this->addSql('ALTER TABLE manifests DROP FOREIGN KEY FK_manifests_completed_by');
        $this->addSql('ALTER TABLE manifests DROP FOREIGN KEY FK_manifests_previous_broker');
        $this->addSql('ALTER TABLE users DROP FOREIGN KEY FK_users_deactivated_by');
        
        // Drop columns from manifests
        $this->addSql('ALTER TABLE manifests DROP COLUMN broker_inactive_since');
        $this->addSql('ALTER TABLE manifests DROP COLUMN transferred_at');
        $this->addSql('ALTER TABLE manifests DROP COLUMN previous_broker_id');
        $this->addSql('ALTER TABLE manifests DROP COLUMN completed_by_id');
        $this->addSql('ALTER TABLE manifests DROP COLUMN completed_at');
        $this->addSql('ALTER TABLE manifests DROP COLUMN archived_for_broker');
        
        // Drop columns from users
        $this->addSql('ALTER TABLE users DROP COLUMN deactivation_reason');
        $this->addSql('ALTER TABLE users DROP COLUMN deactivated_by_id');
        $this->addSql('ALTER TABLE users DROP COLUMN deactivated_at');
        $this->addSql('ALTER TABLE users DROP COLUMN is_active');
    }
}
