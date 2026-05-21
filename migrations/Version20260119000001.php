<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Performance optimization migration - Add database indexes for frequently queried columns
 * and composite indexes for common search patterns
 */
final class Version20260119000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add performance indexes for frequently queried columns and common search patterns';
    }

    public function up(Schema $schema): void
    {
        // Add indexes for frequently queried columns
        
        // Users table - role and status are frequently queried for dashboard filtering
        $this->addSql('CREATE INDEX idx_user_role ON users (role)');
        $this->addSql('CREATE INDEX idx_user_status ON users (status)');
        $this->addSql('CREATE INDEX idx_user_created_at ON users (created_at)');
        
        // Accreditation submissions - status is frequently queried for workflow filtering
        $this->addSql('CREATE INDEX idx_accreditation_status ON accreditation_submissions (status)');
        $this->addSql('CREATE INDEX idx_accreditation_submitted_at ON accreditation_submissions (submitted_at)');
        
        // Payment verifications - status is frequently queried for accounting dashboard
        $this->addSql('CREATE INDEX idx_payment_status ON payment_verifications (status)');
        $this->addSql('CREATE INDEX idx_payment_created_at ON payment_verifications (created_at)');
        
        // Shipment records - arrival dates are frequently queried for search
        $this->addSql('CREATE INDEX idx_shipment_noa_date ON shipment_records (notice_of_arrival_date)');
        $this->addSql('CREATE INDEX idx_shipment_actual_date ON shipment_records (actual_arrival_date)');
        $this->addSql('CREATE INDEX idx_shipment_created_at ON shipment_records (created_at)');
        
        // Form configurations - type and status for form builder
        $this->addSql('CREATE INDEX idx_form_type ON form_configurations (type)');
        $this->addSql('CREATE INDEX idx_form_status ON form_configurations (status)');
        
        // Stored files - category for file management
        $this->addSql('CREATE INDEX idx_file_category ON stored_files (category)');
        $this->addSql('CREATE INDEX idx_file_uploaded_at ON stored_files (uploaded_at)');
        
        // Add composite indexes for common search patterns
        
        // Accreditation submissions by applicant and status (for user dashboard)
        $this->addSql('CREATE INDEX idx_accreditation_applicant_status ON accreditation_submissions (applicant_id, status)');
        
        // Accreditation submissions by evaluator and status (for evaluator dashboard)
        $this->addSql('CREATE INDEX idx_accreditation_evaluator_status ON accreditation_submissions (evaluator_id, status)');
        
        // Shipment records by created_by and date (for SL-Staff dashboard)
        $this->addSql('CREATE INDEX idx_shipment_creator_date ON shipment_records (created_by_id, created_at)');
        
        // Payment verifications by broker and status (for broker dashboard)
        $this->addSql('CREATE INDEX idx_payment_broker_status ON payment_verifications (broker_id, status)');
        
        // Audit logs by user and timestamp (for audit trail queries)
        $this->addSql('CREATE INDEX idx_audit_user_timestamp ON audit_logs (user_id, timestamp)');
        
        // Form configurations by type and version (for active form lookup)
        $this->addSql('CREATE INDEX idx_form_type_version ON form_configurations (type, version, status)');
        
        // Shipment search optimization - date range queries
        $this->addSql('CREATE INDEX idx_shipment_date_range ON shipment_records (notice_of_arrival_date, actual_arrival_date)');
    }

    public function down(Schema $schema): void
    {
        // Remove all the indexes added in up()
        $this->addSql('DROP INDEX idx_user_role ON users');
        $this->addSql('DROP INDEX idx_user_status ON users');
        $this->addSql('DROP INDEX idx_user_created_at ON users');
        
        $this->addSql('DROP INDEX idx_accreditation_status ON accreditation_submissions');
        $this->addSql('DROP INDEX idx_accreditation_submitted_at ON accreditation_submissions');
        
        $this->addSql('DROP INDEX idx_payment_status ON payment_verifications');
        $this->addSql('DROP INDEX idx_payment_created_at ON payment_verifications');
        
        $this->addSql('DROP INDEX idx_shipment_noa_date ON shipment_records');
        $this->addSql('DROP INDEX idx_shipment_actual_date ON shipment_records');
        $this->addSql('DROP INDEX idx_shipment_created_at ON shipment_records');
        
        $this->addSql('DROP INDEX idx_form_type ON form_configurations');
        $this->addSql('DROP INDEX idx_form_status ON form_configurations');
        
        $this->addSql('DROP INDEX idx_file_category ON stored_files');
        $this->addSql('DROP INDEX idx_file_uploaded_at ON stored_files');
        
        $this->addSql('DROP INDEX idx_accreditation_applicant_status ON accreditation_submissions');
        $this->addSql('DROP INDEX idx_accreditation_evaluator_status ON accreditation_submissions');
        $this->addSql('DROP INDEX idx_shipment_creator_date ON shipment_records');
        $this->addSql('DROP INDEX idx_payment_broker_status ON payment_verifications');
        $this->addSql('DROP INDEX idx_audit_user_timestamp ON audit_logs');
        $this->addSql('DROP INDEX idx_form_type_version ON form_configurations');
        $this->addSql('DROP INDEX idx_shipment_date_range ON shipment_records');
    }
}