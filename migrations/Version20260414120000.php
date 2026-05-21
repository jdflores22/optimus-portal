<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Broker-Consignee Relationship Management - New Tables
 * Creates: referral_codes, consignee_broker_relationships, broker_transfer_requests
 */
final class Version20260414120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create tables for broker-consignee relationship management system';
    }

    public function up(Schema $schema): void
    {
        // Create referral_codes table
        $this->addSql('CREATE TABLE referral_codes (
            id INT AUTO_INCREMENT NOT NULL,
            consignee_id INT NOT NULL,
            created_by_id INT NOT NULL,
            code VARCHAR(50) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            max_uses INT DEFAULT NULL COMMENT \'NULL = unlimited\',
            current_uses INT NOT NULL DEFAULT 0,
            expires_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            deactivated_at DATETIME DEFAULT NULL,
            UNIQUE INDEX UNIQ_referral_code (code),
            INDEX IDX_referral_consignee (consignee_id),
            INDEX IDX_referral_active (is_active),
            INDEX IDX_referral_created_by (created_by_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Create consignee_broker_relationships table
        $this->addSql('CREATE TABLE consignee_broker_relationships (
            id INT AUTO_INCREMENT NOT NULL,
            consignee_id INT NOT NULL,
            broker_id INT NOT NULL,
            referral_code_id INT NOT NULL,
            suspended_by_id INT DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT \'active\' COMMENT \'active, suspended, terminated\',
            created_at DATETIME NOT NULL,
            suspended_at DATETIME DEFAULT NULL,
            suspension_reason TEXT DEFAULT NULL,
            UNIQUE INDEX UNIQ_relationship (consignee_id, broker_id),
            INDEX IDX_cbr_consignee (consignee_id),
            INDEX IDX_cbr_broker (broker_id),
            INDEX IDX_cbr_status (status),
            INDEX IDX_cbr_referral (referral_code_id),
            INDEX IDX_cbr_suspended_by (suspended_by_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Create broker_transfer_requests table
        $this->addSql('CREATE TABLE broker_transfer_requests (
            id INT AUTO_INCREMENT NOT NULL,
            manifest_id INT NOT NULL,
            consignee_id INT NOT NULL,
            old_broker_id INT NOT NULL,
            new_broker_id INT NOT NULL,
            requested_by_id INT NOT NULL,
            reviewed_by_id INT DEFAULT NULL,
            reason TEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT \'pending\' COMMENT \'pending, approved, rejected\',
            requested_at DATETIME NOT NULL,
            reviewed_at DATETIME DEFAULT NULL,
            review_notes TEXT DEFAULT NULL,
            INDEX IDX_btr_manifest (manifest_id),
            INDEX IDX_btr_status (status),
            INDEX IDX_btr_consignee (consignee_id),
            INDEX IDX_btr_old_broker (old_broker_id),
            INDEX IDX_btr_new_broker (new_broker_id),
            INDEX IDX_btr_requested_by (requested_by_id),
            INDEX IDX_btr_reviewed_by (reviewed_by_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Add foreign keys for referral_codes
        $this->addSql('ALTER TABLE referral_codes 
            ADD CONSTRAINT FK_referral_consignee 
            FOREIGN KEY (consignee_id) REFERENCES users (id) ON DELETE CASCADE');
        
        $this->addSql('ALTER TABLE referral_codes 
            ADD CONSTRAINT FK_referral_created_by 
            FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE CASCADE');

        // Add foreign keys for consignee_broker_relationships
        $this->addSql('ALTER TABLE consignee_broker_relationships 
            ADD CONSTRAINT FK_cbr_consignee 
            FOREIGN KEY (consignee_id) REFERENCES users (id) ON DELETE CASCADE');
        
        $this->addSql('ALTER TABLE consignee_broker_relationships 
            ADD CONSTRAINT FK_cbr_broker 
            FOREIGN KEY (broker_id) REFERENCES users (id) ON DELETE CASCADE');
        
        $this->addSql('ALTER TABLE consignee_broker_relationships 
            ADD CONSTRAINT FK_cbr_referral 
            FOREIGN KEY (referral_code_id) REFERENCES referral_codes (id) ON DELETE RESTRICT');
        
        $this->addSql('ALTER TABLE consignee_broker_relationships 
            ADD CONSTRAINT FK_cbr_suspended_by 
            FOREIGN KEY (suspended_by_id) REFERENCES users (id) ON DELETE SET NULL');

        // Add foreign keys for broker_transfer_requests
        $this->addSql('ALTER TABLE broker_transfer_requests 
            ADD CONSTRAINT FK_btr_manifest 
            FOREIGN KEY (manifest_id) REFERENCES manifests (id) ON DELETE CASCADE');
        
        $this->addSql('ALTER TABLE broker_transfer_requests 
            ADD CONSTRAINT FK_btr_consignee 
            FOREIGN KEY (consignee_id) REFERENCES users (id) ON DELETE CASCADE');
        
        $this->addSql('ALTER TABLE broker_transfer_requests 
            ADD CONSTRAINT FK_btr_old_broker 
            FOREIGN KEY (old_broker_id) REFERENCES users (id) ON DELETE CASCADE');
        
        $this->addSql('ALTER TABLE broker_transfer_requests 
            ADD CONSTRAINT FK_btr_new_broker 
            FOREIGN KEY (new_broker_id) REFERENCES users (id) ON DELETE CASCADE');
        
        $this->addSql('ALTER TABLE broker_transfer_requests 
            ADD CONSTRAINT FK_btr_requested_by 
            FOREIGN KEY (requested_by_id) REFERENCES users (id) ON DELETE CASCADE');
        
        $this->addSql('ALTER TABLE broker_transfer_requests 
            ADD CONSTRAINT FK_btr_reviewed_by 
            FOREIGN KEY (reviewed_by_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // Drop foreign keys first
        $this->addSql('ALTER TABLE broker_transfer_requests DROP FOREIGN KEY FK_btr_manifest');
        $this->addSql('ALTER TABLE broker_transfer_requests DROP FOREIGN KEY FK_btr_consignee');
        $this->addSql('ALTER TABLE broker_transfer_requests DROP FOREIGN KEY FK_btr_old_broker');
        $this->addSql('ALTER TABLE broker_transfer_requests DROP FOREIGN KEY FK_btr_new_broker');
        $this->addSql('ALTER TABLE broker_transfer_requests DROP FOREIGN KEY FK_btr_requested_by');
        $this->addSql('ALTER TABLE broker_transfer_requests DROP FOREIGN KEY FK_btr_reviewed_by');
        
        $this->addSql('ALTER TABLE consignee_broker_relationships DROP FOREIGN KEY FK_cbr_consignee');
        $this->addSql('ALTER TABLE consignee_broker_relationships DROP FOREIGN KEY FK_cbr_broker');
        $this->addSql('ALTER TABLE consignee_broker_relationships DROP FOREIGN KEY FK_cbr_referral');
        $this->addSql('ALTER TABLE consignee_broker_relationships DROP FOREIGN KEY FK_cbr_suspended_by');
        
        $this->addSql('ALTER TABLE referral_codes DROP FOREIGN KEY FK_referral_consignee');
        $this->addSql('ALTER TABLE referral_codes DROP FOREIGN KEY FK_referral_created_by');

        // Drop tables
        $this->addSql('DROP TABLE broker_transfer_requests');
        $this->addSql('DROP TABLE consignee_broker_relationships');
        $this->addSql('DROP TABLE referral_codes');
    }
}
