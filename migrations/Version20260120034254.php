<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260120034254 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE accreditation_submissions CHANGE submitted_data submitted_data JSON NOT NULL, CHANGE evaluated_at evaluated_at DATETIME DEFAULT NULL, CHANGE approved_at approved_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE audit_logs CHANGE changes changes JSON NOT NULL');
        $this->addSql('ALTER TABLE form_configurations CHANGE fields fields JSON NOT NULL, CHANGE published_at published_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE payment_verifications CHANGE verified_at verified_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE shipment_records CHANGE actual_arrival_date actual_arrival_date DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD email_verification_token VARCHAR(255) DEFAULT NULL, ADD email_verification_token_expires_at DATETIME DEFAULT NULL, ADD email_verified_at DATETIME DEFAULT NULL, CHANGE locked_until locked_until DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE accreditation_submissions CHANGE submitted_data submitted_data LONGTEXT NOT NULL COLLATE `utf8mb4_bin`, CHANGE evaluated_at evaluated_at DATETIME DEFAULT \'NULL\', CHANGE approved_at approved_at DATETIME DEFAULT \'NULL\'');
        $this->addSql('ALTER TABLE audit_logs CHANGE changes changes LONGTEXT NOT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE form_configurations CHANGE fields fields LONGTEXT NOT NULL COLLATE `utf8mb4_bin`, CHANGE published_at published_at DATETIME DEFAULT \'NULL\'');
        $this->addSql('ALTER TABLE payment_verifications CHANGE verified_at verified_at DATETIME DEFAULT \'NULL\'');
        $this->addSql('ALTER TABLE shipment_records CHANGE actual_arrival_date actual_arrival_date DATETIME DEFAULT \'NULL\'');
        $this->addSql('ALTER TABLE users DROP email_verification_token, DROP email_verification_token_expires_at, DROP email_verified_at, CHANGE locked_until locked_until DATETIME DEFAULT \'NULL\'');
    }
}
