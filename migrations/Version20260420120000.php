<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Container-Based eDO Workflow - Task 1.1: Create NOA table
 */
final class Version20260420120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create NOA (Notice of Arrival) table with relationships to consignees, users, and containers';
    }

    public function up(Schema $schema): void
    {
        // Create NOA table
        $this->addSql('CREATE TABLE noa (
            id INT AUTO_INCREMENT NOT NULL,
            noa_number VARCHAR(50) NOT NULL,
            bl_number VARCHAR(50) NOT NULL,
            vessel_number VARCHAR(50) NOT NULL,
            eta DATETIME NOT NULL,
            cy_location VARCHAR(100) NOT NULL,
            consignee_id INT NOT NULL,
            created_by_id INT NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE INDEX UNIQ_noa_noa_number (noa_number),
            INDEX idx_noa_noa_number (noa_number),
            INDEX idx_noa_bl_number (bl_number),
            INDEX idx_noa_consignee (consignee_id),
            INDEX idx_noa_created_at (created_at),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Add foreign keys
        $this->addSql('ALTER TABLE noa 
            ADD CONSTRAINT FK_noa_consignee 
            FOREIGN KEY (consignee_id) REFERENCES users (id) ON DELETE RESTRICT');

        $this->addSql('ALTER TABLE noa 
            ADD CONSTRAINT FK_noa_created_by 
            FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        // Drop foreign keys first
        $this->addSql('ALTER TABLE noa DROP FOREIGN KEY FK_noa_consignee');
        $this->addSql('ALTER TABLE noa DROP FOREIGN KEY FK_noa_created_by');

        // Drop table
        $this->addSql('DROP TABLE noa');
    }
}
