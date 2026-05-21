<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260116085556 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE accreditation_submissions (id INT AUTO_INCREMENT NOT NULL, submitted_data JSON NOT NULL, status VARCHAR(255) NOT NULL, submitted_at DATETIME NOT NULL, evaluated_at DATETIME DEFAULT NULL, approved_at DATETIME DEFAULT NULL, denial_reason LONGTEXT DEFAULT NULL, applicant_id INT NOT NULL, form_configuration_id INT NOT NULL, evaluator_id INT DEFAULT NULL, final_approver_id INT DEFAULT NULL, INDEX IDX_740E89FC97139001 (applicant_id), INDEX IDX_740E89FC8C4C21F5 (form_configuration_id), INDEX IDX_740E89FC43575BE2 (evaluator_id), INDEX IDX_740E89FC4D99C073 (final_approver_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE audit_logs (id INT AUTO_INCREMENT NOT NULL, action VARCHAR(100) NOT NULL, entity_type VARCHAR(100) NOT NULL, entity_id INT NOT NULL, changes JSON NOT NULL, ip_address VARCHAR(45) NOT NULL, timestamp DATETIME NOT NULL, user_id INT NOT NULL, related_edo_id INT DEFAULT NULL, INDEX IDX_D62F2858A76ED395 (user_id), INDEX IDX_D62F2858D3C06D16 (related_edo_id), INDEX idx_audit_timestamp (timestamp), INDEX idx_audit_entity (entity_type, entity_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE brokers (business_name VARCHAR(255) NOT NULL, id INT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE consignees (business_name VARCHAR(255) NOT NULL, broker_id INT DEFAULT NULL, id INT NOT NULL, INDEX IDX_2EB221F6CC064FC (broker_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE electronic_delivery_orders (id INT AUTO_INCREMENT NOT NULL, edo_number VARCHAR(100) NOT NULL, pdf_path VARCHAR(500) NOT NULL, generated_at DATETIME NOT NULL, payment_id INT NOT NULL, UNIQUE INDEX UNIQ_3631ED272ECFE254 (edo_number), UNIQUE INDEX UNIQ_3631ED274C3A3BB (payment_id), INDEX idx_edo_number (edo_number), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE form_configurations (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, type VARCHAR(255) NOT NULL, version INT DEFAULT 1 NOT NULL, status VARCHAR(255) NOT NULL, fields JSON NOT NULL, created_at DATETIME NOT NULL, published_at DATETIME DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE payment_verifications (id INT AUTO_INCREMENT NOT NULL, proof_file_path VARCHAR(500) NOT NULL, status VARCHAR(255) NOT NULL, verified_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, shipment_id INT NOT NULL, broker_id INT NOT NULL, verified_by_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_7182EE8B7BE036FC (shipment_id), INDEX IDX_7182EE8B6CC064FC (broker_id), INDEX IDX_7182EE8B69F4B775 (verified_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE shipment_records (id INT AUTO_INCREMENT NOT NULL, manifest_number VARCHAR(100) NOT NULL, notice_of_arrival_date DATETIME NOT NULL, actual_arrival_date DATETIME DEFAULT NULL, billing_information LONGTEXT NOT NULL, created_at DATETIME NOT NULL, created_by_id INT NOT NULL, UNIQUE INDEX UNIQ_77355EE3A58B0EBC (manifest_number), INDEX IDX_77355EE3B03A8386 (created_by_id), INDEX idx_manifest_number (manifest_number), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE shipment_broker_access (shipment_id INT NOT NULL, broker_id INT NOT NULL, INDEX IDX_32CF917C7BE036FC (shipment_id), INDEX IDX_32CF917C6CC064FC (broker_id), PRIMARY KEY (shipment_id, broker_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE staff_users (first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, department VARCHAR(100) NOT NULL, id INT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE stored_files (id INT AUTO_INCREMENT NOT NULL, file_id VARCHAR(36) NOT NULL, original_name VARCHAR(255) NOT NULL, encrypted_path VARCHAR(500) NOT NULL, mime_type VARCHAR(100) NOT NULL, size INT NOT NULL, category VARCHAR(50) NOT NULL, uploaded_at DATETIME NOT NULL, uploaded_by_id INT NOT NULL, UNIQUE INDEX UNIQ_427ECBFB93CB796C (file_id), INDEX IDX_427ECBFBA2B28FE8 (uploaded_by_id), INDEX idx_file_id (file_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE users (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, password_hash VARCHAR(255) NOT NULL, role VARCHAR(255) NOT NULL, status VARCHAR(255) NOT NULL, failed_login_attempts INT DEFAULT 0 NOT NULL, locked_until DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, type VARCHAR(255) NOT NULL, UNIQUE INDEX UNIQ_1483A5E9E7927C74 (email), INDEX idx_user_email (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE accreditation_submissions ADD CONSTRAINT FK_740E89FC97139001 FOREIGN KEY (applicant_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE accreditation_submissions ADD CONSTRAINT FK_740E89FC8C4C21F5 FOREIGN KEY (form_configuration_id) REFERENCES form_configurations (id)');
        $this->addSql('ALTER TABLE accreditation_submissions ADD CONSTRAINT FK_740E89FC43575BE2 FOREIGN KEY (evaluator_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE accreditation_submissions ADD CONSTRAINT FK_740E89FC4D99C073 FOREIGN KEY (final_approver_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE audit_logs ADD CONSTRAINT FK_D62F2858A76ED395 FOREIGN KEY (user_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE audit_logs ADD CONSTRAINT FK_D62F2858D3C06D16 FOREIGN KEY (related_edo_id) REFERENCES electronic_delivery_orders (id)');
        $this->addSql('ALTER TABLE brokers ADD CONSTRAINT FK_AAF38CDFBF396750 FOREIGN KEY (id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE consignees ADD CONSTRAINT FK_2EB221F6CC064FC FOREIGN KEY (broker_id) REFERENCES brokers (id)');
        $this->addSql('ALTER TABLE consignees ADD CONSTRAINT FK_2EB221FBF396750 FOREIGN KEY (id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE electronic_delivery_orders ADD CONSTRAINT FK_3631ED274C3A3BB FOREIGN KEY (payment_id) REFERENCES payment_verifications (id)');
        $this->addSql('ALTER TABLE payment_verifications ADD CONSTRAINT FK_7182EE8B7BE036FC FOREIGN KEY (shipment_id) REFERENCES shipment_records (id)');
        $this->addSql('ALTER TABLE payment_verifications ADD CONSTRAINT FK_7182EE8B6CC064FC FOREIGN KEY (broker_id) REFERENCES brokers (id)');
        $this->addSql('ALTER TABLE payment_verifications ADD CONSTRAINT FK_7182EE8B69F4B775 FOREIGN KEY (verified_by_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE shipment_records ADD CONSTRAINT FK_77355EE3B03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE shipment_broker_access ADD CONSTRAINT FK_32CF917C7BE036FC FOREIGN KEY (shipment_id) REFERENCES shipment_records (id)');
        $this->addSql('ALTER TABLE shipment_broker_access ADD CONSTRAINT FK_32CF917C6CC064FC FOREIGN KEY (broker_id) REFERENCES brokers (id)');
        $this->addSql('ALTER TABLE staff_users ADD CONSTRAINT FK_69660D2EBF396750 FOREIGN KEY (id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE stored_files ADD CONSTRAINT FK_427ECBFBA2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES users (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE accreditation_submissions DROP FOREIGN KEY FK_740E89FC97139001');
        $this->addSql('ALTER TABLE accreditation_submissions DROP FOREIGN KEY FK_740E89FC8C4C21F5');
        $this->addSql('ALTER TABLE accreditation_submissions DROP FOREIGN KEY FK_740E89FC43575BE2');
        $this->addSql('ALTER TABLE accreditation_submissions DROP FOREIGN KEY FK_740E89FC4D99C073');
        $this->addSql('ALTER TABLE audit_logs DROP FOREIGN KEY FK_D62F2858A76ED395');
        $this->addSql('ALTER TABLE audit_logs DROP FOREIGN KEY FK_D62F2858D3C06D16');
        $this->addSql('ALTER TABLE brokers DROP FOREIGN KEY FK_AAF38CDFBF396750');
        $this->addSql('ALTER TABLE consignees DROP FOREIGN KEY FK_2EB221F6CC064FC');
        $this->addSql('ALTER TABLE consignees DROP FOREIGN KEY FK_2EB221FBF396750');
        $this->addSql('ALTER TABLE electronic_delivery_orders DROP FOREIGN KEY FK_3631ED274C3A3BB');
        $this->addSql('ALTER TABLE payment_verifications DROP FOREIGN KEY FK_7182EE8B7BE036FC');
        $this->addSql('ALTER TABLE payment_verifications DROP FOREIGN KEY FK_7182EE8B6CC064FC');
        $this->addSql('ALTER TABLE payment_verifications DROP FOREIGN KEY FK_7182EE8B69F4B775');
        $this->addSql('ALTER TABLE shipment_records DROP FOREIGN KEY FK_77355EE3B03A8386');
        $this->addSql('ALTER TABLE shipment_broker_access DROP FOREIGN KEY FK_32CF917C7BE036FC');
        $this->addSql('ALTER TABLE shipment_broker_access DROP FOREIGN KEY FK_32CF917C6CC064FC');
        $this->addSql('ALTER TABLE staff_users DROP FOREIGN KEY FK_69660D2EBF396750');
        $this->addSql('ALTER TABLE stored_files DROP FOREIGN KEY FK_427ECBFBA2B28FE8');
        $this->addSql('DROP TABLE accreditation_submissions');
        $this->addSql('DROP TABLE audit_logs');
        $this->addSql('DROP TABLE brokers');
        $this->addSql('DROP TABLE consignees');
        $this->addSql('DROP TABLE electronic_delivery_orders');
        $this->addSql('DROP TABLE form_configurations');
        $this->addSql('DROP TABLE payment_verifications');
        $this->addSql('DROP TABLE shipment_records');
        $this->addSql('DROP TABLE shipment_broker_access');
        $this->addSql('DROP TABLE staff_users');
        $this->addSql('DROP TABLE stored_files');
        $this->addSql('DROP TABLE users');
    }
}
