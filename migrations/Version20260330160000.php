<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Update terminology from "pre-advice" to "FREE-ADVICE" in database comments and descriptions
 * Part of Container Dwell Time Management - Task 7.1
 */
final class Version20260330160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Update database field labels and descriptions from "pre-advice" to "FREE-ADVICE" terminology';
    }

    public function up(Schema $schema): void
    {
        // Update table comments to use FREE-ADVICE terminology
        $this->addSql("ALTER TABLE pre_advice_requests COMMENT = 'FREE-ADVICE requests for container returns'");
        $this->addSql("ALTER TABLE geotag_photos COMMENT = 'Geotag photos for FREE-ADVICE verification'");
        
        // Update column comments for better clarity
        $this->addSql("ALTER TABLE pre_advice_requests 
            MODIFY COLUMN status VARCHAR(20) NOT NULL COMMENT 'FREE-ADVICE request status',
            MODIFY COLUMN rejection_reason TEXT DEFAULT NULL COMMENT 'Reason for FREE-ADVICE rejection',
            MODIFY COLUMN payment_reference VARCHAR(100) DEFAULT NULL COMMENT 'Payment reference for FREE-ADVICE request',
            MODIFY COLUMN qr_code VARCHAR(255) DEFAULT NULL COMMENT 'QR code for FREE-ADVICE verification',
            MODIFY COLUMN edo_number VARCHAR(50) DEFAULT NULL COMMENT 'EDO number generated for FREE-ADVICE',
            MODIFY COLUMN verified_at DATETIME DEFAULT NULL COMMENT 'Timestamp when FREE-ADVICE was verified'
        ");
        
        $this->addSql("ALTER TABLE geotag_photos 
            MODIFY COLUMN pre_advice_request_id INT NOT NULL COMMENT 'Reference to FREE-ADVICE request',
            MODIFY COLUMN is_verified TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether photo is verified for FREE-ADVICE',
            MODIFY COLUMN verification_notes TEXT DEFAULT NULL COMMENT 'Verification notes for FREE-ADVICE photo'
        ");
    }

    public function down(Schema $schema): void
    {
        // Revert table comments back to pre-advice terminology
        $this->addSql("ALTER TABLE pre_advice_requests COMMENT = 'Pre-advice requests for container returns'");
        $this->addSql("ALTER TABLE geotag_photos COMMENT = 'Geotag photos for pre-advice verification'");
        
        // Revert column comments
        $this->addSql("ALTER TABLE pre_advice_requests 
            MODIFY COLUMN status VARCHAR(20) NOT NULL COMMENT 'Pre-advice request status',
            MODIFY COLUMN rejection_reason TEXT DEFAULT NULL COMMENT 'Reason for pre-advice rejection',
            MODIFY COLUMN payment_reference VARCHAR(100) DEFAULT NULL COMMENT 'Payment reference for pre-advice request',
            MODIFY COLUMN qr_code VARCHAR(255) DEFAULT NULL COMMENT 'QR code for pre-advice verification',
            MODIFY COLUMN edo_number VARCHAR(50) DEFAULT NULL COMMENT 'EDO number generated for pre-advice',
            MODIFY COLUMN verified_at DATETIME DEFAULT NULL COMMENT 'Timestamp when pre-advice was verified'
        ");
        
        $this->addSql("ALTER TABLE geotag_photos 
            MODIFY COLUMN pre_advice_request_id INT NOT NULL COMMENT 'Reference to pre-advice request',
            MODIFY COLUMN is_verified TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether photo is verified for pre-advice',
            MODIFY COLUMN verification_notes TEXT DEFAULT NULL COMMENT 'Verification notes for pre-advice photo'
        ");
    }
}
