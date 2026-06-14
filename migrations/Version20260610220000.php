<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260610220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add repositioning request tables for CY-to-port export/repositioning workflow (CAO 8-2019 dwell time compliance)';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('repositioning_requests')) {
            $this->addSql('CREATE TABLE repositioning_requests (
                id INT AUTO_INCREMENT NOT NULL,
                shipping_line_id INT NOT NULL,
                source_terminal_id INT NOT NULL,
                destination_terminal_id INT NOT NULL,
                requested_by_id INT NOT NULL,
                reviewed_by_id INT DEFAULT NULL,
                request_number VARCHAR(30) NOT NULL,
                request_type VARCHAR(20) NOT NULL,
                purpose LONGTEXT NOT NULL,
                request_letter VARCHAR(500) DEFAULT NULL,
                container_count INT NOT NULL DEFAULT 0,
                status VARCHAR(20) NOT NULL DEFAULT \'pending\',
                requested_at DATETIME NOT NULL,
                reviewed_at DATETIME DEFAULT NULL,
                review_notes LONGTEXT DEFAULT NULL,
                completed_at DATETIME DEFAULT NULL,
                UNIQUE INDEX UNIQ_RRP_REQUEST_NUMBER (request_number),
                INDEX idx_rr_shipping_line (shipping_line_id),
                INDEX idx_rr_status (status),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

            $this->addSql('ALTER TABLE repositioning_requests ADD CONSTRAINT FK_RRP_SHIPPING_LINE FOREIGN KEY (shipping_line_id) REFERENCES shipping_lines (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE repositioning_requests ADD CONSTRAINT FK_RRP_SOURCE_TERMINAL FOREIGN KEY (source_terminal_id) REFERENCES terminals (id)');
            $this->addSql('ALTER TABLE repositioning_requests ADD CONSTRAINT FK_RRP_DEST_TERMINAL FOREIGN KEY (destination_terminal_id) REFERENCES terminals (id)');
            $this->addSql('ALTER TABLE repositioning_requests ADD CONSTRAINT FK_RRP_REQUESTED_BY FOREIGN KEY (requested_by_id) REFERENCES users (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE repositioning_requests ADD CONSTRAINT FK_RRP_REVIEWED_BY FOREIGN KEY (reviewed_by_id) REFERENCES users (id) ON DELETE SET NULL');
        }

        if (!$schema->hasTable('repositioning_request_items')) {
            $this->addSql('CREATE TABLE repositioning_request_items (
                id INT AUTO_INCREMENT NOT NULL,
                request_id INT NOT NULL,
                container_id INT NOT NULL,
                dwell_time_days INT NOT NULL DEFAULT 0,
                discharge_date DATETIME DEFAULT NULL,
                INDEX IDX_RR_ITEM_REQUEST (request_id),
                INDEX IDX_RR_ITEM_CONTAINER (container_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

            $this->addSql('ALTER TABLE repositioning_request_items ADD CONSTRAINT FK_RR_ITEM_REQUEST FOREIGN KEY (request_id) REFERENCES repositioning_requests (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE repositioning_request_items ADD CONSTRAINT FK_RR_ITEM_CONTAINER FOREIGN KEY (container_id) REFERENCES containers (id) ON DELETE CASCADE');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('repositioning_request_items')) {
            $this->addSql('DROP TABLE repositioning_request_items');
        }
        if ($schema->hasTable('repositioning_requests')) {
            $this->addSql('DROP TABLE repositioning_requests');
        }
    }
}
