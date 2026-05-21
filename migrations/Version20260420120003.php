<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Container-Based eDO Workflow - Task 1.4: Create RegenerationRequest entity
 */
final class Version20260420120003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create regeneration_requests table for eDO regeneration workflow';
    }

    public function up(Schema $schema): void
    {
        // Create regeneration_requests table
        $this->addSql('CREATE TABLE regeneration_requests (
            id INT AUTO_INCREMENT NOT NULL,
            edo_id INT NOT NULL,
            requester_id INT NOT NULL,
            status VARCHAR(30) NOT NULL,
            requested_at DATETIME NOT NULL,
            routed_to_accounting_at DATETIME DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            INDEX idx_regen_req_edo (edo_id),
            INDEX idx_regen_req_requester (requester_id),
            INDEX idx_regen_req_status (status),
            INDEX idx_regen_req_requested_at (requested_at),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Add foreign keys
        $this->addSql('ALTER TABLE regeneration_requests 
            ADD CONSTRAINT FK_regen_req_edo 
            FOREIGN KEY (edo_id) REFERENCES electronic_delivery_orders (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE regeneration_requests 
            ADD CONSTRAINT FK_regen_req_requester 
            FOREIGN KEY (requester_id) REFERENCES users (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        // Drop foreign keys
        $this->addSql('ALTER TABLE regeneration_requests DROP FOREIGN KEY FK_regen_req_requester');
        $this->addSql('ALTER TABLE regeneration_requests DROP FOREIGN KEY FK_regen_req_edo');

        // Drop table
        $this->addSql('DROP TABLE regeneration_requests');
    }
}
