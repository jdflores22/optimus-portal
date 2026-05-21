<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create Terminal Team FREE-ADVICE entities and relationships
 */
final class Version20260128051132 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Terminal Team FREE-ADVICE entities: containers, terminals, terminal_slots, pre_advice_requests, geotag_photos';
    }

    public function up(Schema $schema): void
    {
        // Create containers table
        $this->addSql('CREATE TABLE containers (
            id INT AUTO_INCREMENT NOT NULL,
            container_number VARCHAR(20) NOT NULL UNIQUE,
            size VARCHAR(10) NOT NULL,
            type VARCHAR(20) NOT NULL,
            status VARCHAR(50) NOT NULL,
            current_location VARCHAR(255) DEFAULT NULL,
            expected_return_date DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY(id),
            INDEX idx_container_number (container_number),
            INDEX idx_container_status (status)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Create terminals table
        $this->addSql('CREATE TABLE terminals (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(100) NOT NULL,
            type VARCHAR(20) NOT NULL,
            location VARCHAR(255) NOT NULL,
            daily_capacity INT NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY(id),
            INDEX idx_terminal_type (type),
            INDEX idx_terminal_active (is_active)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Create terminal_slots table
        $this->addSql('CREATE TABLE terminal_slots (
            id INT AUTO_INCREMENT NOT NULL,
            terminal_id INT NOT NULL,
            date DATE NOT NULL,
            capacity INT NOT NULL,
            assigned_count INT NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY(id),
            INDEX idx_terminal_date (terminal_id, date),
            INDEX idx_slot_status (status),
            CONSTRAINT FK_terminal_slots_terminal FOREIGN KEY (terminal_id) REFERENCES terminals (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Create pre_advice_requests table
        $this->addSql('CREATE TABLE pre_advice_requests (
            id INT AUTO_INCREMENT NOT NULL,
            trucker_id INT NOT NULL,
            container_id INT NOT NULL,
            selected_terminal_id INT NOT NULL,
            assigned_slot_id INT DEFAULT NULL,
            verified_by_id INT DEFAULT NULL,
            status VARCHAR(20) NOT NULL,
            rejection_reason TEXT DEFAULT NULL,
            payment_reference VARCHAR(100) NOT NULL,
            qr_code VARCHAR(255) DEFAULT NULL,
            edo_number VARCHAR(50) DEFAULT NULL,
            verified_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY(id),
            INDEX idx_pre_advice_status (status),
            INDEX idx_pre_advice_trucker (trucker_id),
            INDEX idx_pre_advice_container (container_id),
            INDEX idx_pre_advice_terminal (selected_terminal_id),
            INDEX idx_pre_advice_payment (payment_reference),
            CONSTRAINT FK_pre_advice_trucker FOREIGN KEY (trucker_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT FK_pre_advice_container FOREIGN KEY (container_id) REFERENCES containers (id) ON DELETE CASCADE,
            CONSTRAINT FK_pre_advice_terminal FOREIGN KEY (selected_terminal_id) REFERENCES terminals (id) ON DELETE CASCADE,
            CONSTRAINT FK_pre_advice_slot FOREIGN KEY (assigned_slot_id) REFERENCES terminal_slots (id) ON DELETE SET NULL,
            CONSTRAINT FK_pre_advice_verified_by FOREIGN KEY (verified_by_id) REFERENCES users (id) ON DELETE SET NULL
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Create geotag_photos table
        $this->addSql('CREATE TABLE geotag_photos (
            id INT AUTO_INCREMENT NOT NULL,
            pre_advice_request_id INT NOT NULL,
            filename VARCHAR(255) NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            latitude DECIMAL(10, 8) NOT NULL,
            longitude DECIMAL(11, 8) NOT NULL,
            captured_at DATETIME NOT NULL,
            is_verified TINYINT(1) NOT NULL DEFAULT 0,
            verification_notes TEXT DEFAULT NULL,
            uploaded_at DATETIME NOT NULL,
            PRIMARY KEY(id),
            INDEX idx_geotag_pre_advice (pre_advice_request_id),
            INDEX idx_geotag_verified (is_verified),
            CONSTRAINT FK_geotag_pre_advice FOREIGN KEY (pre_advice_request_id) REFERENCES pre_advice_requests (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Add enum constraints for better data integrity
        $this->addSql('ALTER TABLE containers ADD CONSTRAINT chk_container_status 
            CHECK (status IN (\'available_for_return\', \'in_transit\', \'at_terminal\', \'returned\', \'maintenance\'))');
        
        $this->addSql('ALTER TABLE terminals ADD CONSTRAINT chk_terminal_type 
            CHECK (type IN (\'CY\', \'ATI\', \'ICTSI\'))');
        
        $this->addSql('ALTER TABLE terminal_slots ADD CONSTRAINT chk_slot_status 
            CHECK (status IN (\'available\', \'full\', \'blocked\'))');
        
        $this->addSql('ALTER TABLE pre_advice_requests ADD CONSTRAINT chk_pre_advice_status 
            CHECK (status IN (\'pending\', \'verified\', \'rejected\', \'completed\', \'cancelled\'))');
    }

    public function down(Schema $schema): void
    {
        // Drop tables in reverse order to respect foreign key constraints
        $this->addSql('DROP TABLE geotag_photos');
        $this->addSql('DROP TABLE pre_advice_requests');
        $this->addSql('DROP TABLE terminal_slots');
        $this->addSql('DROP TABLE terminals');
        $this->addSql('DROP TABLE containers');
    }
}
