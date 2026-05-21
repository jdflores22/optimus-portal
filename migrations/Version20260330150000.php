<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Update database field labels and constraints to use FREE-ADVICE terminology
 * Part of Requirements 5.3 - FREE-ADVICE Terminology Update
 */
final class Version20260330150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Update database field labels and constraints to use FREE-ADVICE terminology while maintaining backward compatibility';
    }

    public function up(Schema $schema): void
    {
        // Update table comments to use FREE-ADVICE terminology
        $this->addSql('ALTER TABLE pre_advice_requests COMMENT = "FREE-ADVICE requests for container returns - maintains backward compatibility with existing API integrations"');
        
        // Update column comments to use FREE-ADVICE terminology
        $this->addSql('ALTER TABLE pre_advice_requests MODIFY COLUMN id INT AUTO_INCREMENT NOT NULL COMMENT "Unique identifier for FREE-ADVICE request"');
        $this->addSql('ALTER TABLE pre_advice_requests MODIFY COLUMN trucker_id INT NOT NULL COMMENT "Trucker who submitted the FREE-ADVICE request"');
        $this->addSql('ALTER TABLE pre_advice_requests MODIFY COLUMN container_id INT NOT NULL COMMENT "Container associated with FREE-ADVICE request"');
        $this->addSql('ALTER TABLE pre_advice_requests MODIFY COLUMN selected_terminal_id INT NOT NULL COMMENT "Terminal selected for FREE-ADVICE processing"');
        $this->addSql('ALTER TABLE pre_advice_requests MODIFY COLUMN assigned_slot_id INT DEFAULT NULL COMMENT "Terminal slot assigned for FREE-ADVICE processing"');
        $this->addSql('ALTER TABLE pre_advice_requests MODIFY COLUMN verified_by_id INT DEFAULT NULL COMMENT "Terminal team user who verified the FREE-ADVICE request"');
        $this->addSql('ALTER TABLE pre_advice_requests MODIFY COLUMN status VARCHAR(20) NOT NULL COMMENT "Current status of FREE-ADVICE request (pending, verified, rejected, completed, cancelled)"');
        $this->addSql('ALTER TABLE pre_advice_requests MODIFY COLUMN rejection_reason TEXT DEFAULT NULL COMMENT "Reason for FREE-ADVICE request rejection"');
        $this->addSql('ALTER TABLE pre_advice_requests MODIFY COLUMN payment_reference VARCHAR(100) NOT NULL COMMENT "Payment reference for FREE-ADVICE processing fee"');
        $this->addSql('ALTER TABLE pre_advice_requests MODIFY COLUMN qr_code VARCHAR(255) DEFAULT NULL COMMENT "QR code generated for verified FREE-ADVICE request"');
        $this->addSql('ALTER TABLE pre_advice_requests MODIFY COLUMN edo_number VARCHAR(50) DEFAULT NULL COMMENT "EDO number generated for completed FREE-ADVICE request"');
        $this->addSql('ALTER TABLE pre_advice_requests MODIFY COLUMN verified_at DATETIME DEFAULT NULL COMMENT "Timestamp when FREE-ADVICE request was verified"');
        $this->addSql('ALTER TABLE pre_advice_requests MODIFY COLUMN created_at DATETIME NOT NULL COMMENT "Timestamp when FREE-ADVICE request was created"');
        $this->addSql('ALTER TABLE pre_advice_requests MODIFY COLUMN updated_at DATETIME NOT NULL COMMENT "Timestamp when FREE-ADVICE request was last updated"');
        
        // Update geotag_photos table comments
        $this->addSql('ALTER TABLE geotag_photos COMMENT = "Geotag photos uploaded for FREE-ADVICE request verification"');
        $this->addSql('ALTER TABLE geotag_photos MODIFY COLUMN pre_advice_request_id INT NOT NULL COMMENT "FREE-ADVICE request associated with this photo"');
        $this->addSql('ALTER TABLE geotag_photos MODIFY COLUMN filename VARCHAR(255) NOT NULL COMMENT "Filename of uploaded photo for FREE-ADVICE verification"');
        $this->addSql('ALTER TABLE geotag_photos MODIFY COLUMN original_name VARCHAR(255) NOT NULL COMMENT "Original filename of photo uploaded for FREE-ADVICE"');
        $this->addSql('ALTER TABLE geotag_photos MODIFY COLUMN latitude DECIMAL(10, 8) NOT NULL COMMENT "GPS latitude coordinate for FREE-ADVICE photo verification"');
        $this->addSql('ALTER TABLE geotag_photos MODIFY COLUMN longitude DECIMAL(11, 8) NOT NULL COMMENT "GPS longitude coordinate for FREE-ADVICE photo verification"');
        $this->addSql('ALTER TABLE geotag_photos MODIFY COLUMN captured_at DATETIME NOT NULL COMMENT "Timestamp when FREE-ADVICE verification photo was captured"');
        $this->addSql('ALTER TABLE geotag_photos MODIFY COLUMN is_verified TINYINT(1) NOT NULL DEFAULT 0 COMMENT "Whether FREE-ADVICE photo has been verified by terminal team"');
        $this->addSql('ALTER TABLE geotag_photos MODIFY COLUMN verification_notes TEXT DEFAULT NULL COMMENT "Terminal team notes for FREE-ADVICE photo verification"');
        $this->addSql('ALTER TABLE geotag_photos MODIFY COLUMN uploaded_at DATETIME NOT NULL COMMENT "Timestamp when FREE-ADVICE photo was uploaded"');
        
        // Update constraint names and descriptions to reflect FREE-ADVICE terminology
        $this->addSql('ALTER TABLE pre_advice_requests DROP CONSTRAINT chk_pre_advice_status');
        $this->addSql('ALTER TABLE pre_advice_requests ADD CONSTRAINT chk_free_advice_status 
            CHECK (status IN (\'pending\', \'verified\', \'rejected\', \'completed\', \'cancelled\'))');
            
        // Update index names to reflect FREE-ADVICE terminology (maintaining functionality)
        $this->addSql('ALTER TABLE pre_advice_requests DROP INDEX idx_pre_advice_status');
        $this->addSql('ALTER TABLE pre_advice_requests ADD INDEX idx_free_advice_status (status)');
        
        $this->addSql('ALTER TABLE pre_advice_requests DROP INDEX idx_pre_advice_trucker');
        $this->addSql('ALTER TABLE pre_advice_requests ADD INDEX idx_free_advice_trucker (trucker_id)');
        
        $this->addSql('ALTER TABLE pre_advice_requests DROP INDEX idx_pre_advice_container');
        $this->addSql('ALTER TABLE pre_advice_requests ADD INDEX idx_free_advice_container (container_id)');
        
        $this->addSql('ALTER TABLE pre_advice_requests DROP INDEX idx_pre_advice_terminal');
        $this->addSql('ALTER TABLE pre_advice_requests ADD INDEX idx_free_advice_terminal (selected_terminal_id)');
        
        $this->addSql('ALTER TABLE pre_advice_requests DROP INDEX idx_pre_advice_payment');
        $this->addSql('ALTER TABLE pre_advice_requests ADD INDEX idx_free_advice_payment (payment_reference)');
        
        // Update geotag_photos indexes
        $this->addSql('ALTER TABLE geotag_photos DROP INDEX idx_geotag_pre_advice');
        $this->addSql('ALTER TABLE geotag_photos ADD INDEX idx_geotag_free_advice (pre_advice_request_id)');
    }

    public function down(Schema $schema): void
    {
        // Revert table comments
        $this->addSql('ALTER TABLE pre_advice_requests COMMENT = ""');
        $this->addSql('ALTER TABLE geotag_photos COMMENT = ""');
        
        // Revert column comments (remove comments)
        $this->addSql('ALTER TABLE pre_advice_requests MODIFY COLUMN id INT AUTO_INCREMENT NOT NULL');
        $this->addSql('ALTER TABLE pre_advice_requests MODIFY COLUMN trucker_id INT NOT NULL');
        $this->addSql('ALTER TABLE pre_advice_requests MODIFY COLUMN container_id INT NOT NULL');
        $this->addSql('ALTER TABLE pre_advice_requests MODIFY COLUMN selected_terminal_id INT NOT NULL');
        $this->addSql('ALTER TABLE pre_advice_requests MODIFY COLUMN assigned_slot_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE pre_advice_requests MODIFY COLUMN verified_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE pre_advice_requests MODIFY COLUMN status VARCHAR(20) NOT NULL');
        $this->addSql('ALTER TABLE pre_advice_requests MODIFY COLUMN rejection_reason TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE pre_advice_requests MODIFY COLUMN payment_reference VARCHAR(100) NOT NULL');
        $this->addSql('ALTER TABLE pre_advice_requests MODIFY COLUMN qr_code VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE pre_advice_requests MODIFY COLUMN edo_number VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE pre_advice_requests MODIFY COLUMN verified_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE pre_advice_requests MODIFY COLUMN created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE pre_advice_requests MODIFY COLUMN updated_at DATETIME NOT NULL');
        
        // Revert geotag_photos column comments
        $this->addSql('ALTER TABLE geotag_photos MODIFY COLUMN pre_advice_request_id INT NOT NULL');
        $this->addSql('ALTER TABLE geotag_photos MODIFY COLUMN filename VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE geotag_photos MODIFY COLUMN original_name VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE geotag_photos MODIFY COLUMN latitude DECIMAL(10, 8) NOT NULL');
        $this->addSql('ALTER TABLE geotag_photos MODIFY COLUMN longitude DECIMAL(11, 8) NOT NULL');
        $this->addSql('ALTER TABLE geotag_photos MODIFY COLUMN captured_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE geotag_photos MODIFY COLUMN is_verified TINYINT(1) NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE geotag_photos MODIFY COLUMN verification_notes TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE geotag_photos MODIFY COLUMN uploaded_at DATETIME NOT NULL');
        
        // Revert constraint names
        $this->addSql('ALTER TABLE pre_advice_requests DROP CONSTRAINT chk_free_advice_status');
        $this->addSql('ALTER TABLE pre_advice_requests ADD CONSTRAINT chk_pre_advice_status 
            CHECK (status IN (\'pending\', \'verified\', \'rejected\', \'completed\', \'cancelled\'))');
            
        // Revert index names
        $this->addSql('ALTER TABLE pre_advice_requests DROP INDEX idx_free_advice_status');
        $this->addSql('ALTER TABLE pre_advice_requests ADD INDEX idx_pre_advice_status (status)');
        
        $this->addSql('ALTER TABLE pre_advice_requests DROP INDEX idx_free_advice_trucker');
        $this->addSql('ALTER TABLE pre_advice_requests ADD INDEX idx_pre_advice_trucker (trucker_id)');
        
        $this->addSql('ALTER TABLE pre_advice_requests DROP INDEX idx_free_advice_container');
        $this->addSql('ALTER TABLE pre_advice_requests ADD INDEX idx_pre_advice_container (container_id)');
        
        $this->addSql('ALTER TABLE pre_advice_requests DROP INDEX idx_free_advice_terminal');
        $this->addSql('ALTER TABLE pre_advice_requests ADD INDEX idx_pre_advice_terminal (selected_terminal_id)');
        
        $this->addSql('ALTER TABLE pre_advice_requests DROP INDEX idx_free_advice_payment');
        $this->addSql('ALTER TABLE pre_advice_requests ADD INDEX idx_pre_advice_payment (payment_reference)');
        
        // Revert geotag_photos indexes
        $this->addSql('ALTER TABLE geotag_photos DROP INDEX idx_geotag_free_advice');
        $this->addSql('ALTER TABLE geotag_photos ADD INDEX idx_geotag_pre_advice (pre_advice_request_id)');
    }
}