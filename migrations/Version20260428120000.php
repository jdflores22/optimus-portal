<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration to create edo_renewal_requests table
 * Task 1.1: Create edo_renewal_requests table migration
 * Requirements: 14.1, 15.1
 */
final class Version20260428120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create edo_renewal_requests table for expired eDO renewal workflow';
    }

    public function up(Schema $schema): void
    {
        // Create edo_renewal_requests table
        $this->addSql('
            CREATE TABLE edo_renewal_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                expired_edo_id INT NOT NULL,
                new_edo_id INT NULL,
                requested_by_id INT NOT NULL,
                requested_at DATETIME NOT NULL,
                empty_container_return_date DATETIME NOT NULL,
                overdue_days INT NOT NULL,
                detention_charge_amount DECIMAL(10, 2) NOT NULL,
                status VARCHAR(50) NOT NULL,
                detention_billing_id INT NULL,
                payment_verified BOOLEAN DEFAULT FALSE,
                payment_verified_at DATETIME NULL,
                payment_verified_by_id INT NULL,
                additional_notes TEXT NULL,
                completed_at DATETIME NULL,
                
                INDEX idx_renewal_requests_status (status),
                INDEX idx_renewal_requests_requested_at (requested_at),
                INDEX idx_renewal_requests_expired_edo (expired_edo_id),
                
                CONSTRAINT fk_renewal_requests_expired_edo 
                    FOREIGN KEY (expired_edo_id) 
                    REFERENCES electronic_delivery_orders(id) 
                    ON DELETE CASCADE,
                    
                CONSTRAINT fk_renewal_requests_new_edo 
                    FOREIGN KEY (new_edo_id) 
                    REFERENCES electronic_delivery_orders(id) 
                    ON DELETE SET NULL,
                    
                CONSTRAINT fk_renewal_requests_requested_by 
                    FOREIGN KEY (requested_by_id) 
                    REFERENCES users(id) 
                    ON DELETE CASCADE,
                    
                CONSTRAINT fk_renewal_requests_detention_billing 
                    FOREIGN KEY (detention_billing_id) 
                    REFERENCES billings(id) 
                    ON DELETE SET NULL,
                    
                CONSTRAINT fk_renewal_requests_payment_verified_by 
                    FOREIGN KEY (payment_verified_by_id) 
                    REFERENCES users(id) 
                    ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    public function down(Schema $schema): void
    {
        // Drop edo_renewal_requests table
        $this->addSql('DROP TABLE IF EXISTS edo_renewal_requests');
    }
}
