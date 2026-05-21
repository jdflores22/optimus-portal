<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add payment verification and failure tracking columns to pre_advice_requests table
 */
final class Version20260129140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add payment_verified, payment_verified_at, payment_failure_count, last_payment_failure_reason, and last_payment_failure_at columns to pre_advice_requests table';
    }

    public function up(Schema $schema): void
    {
        // Add payment verification columns
        $this->addSql('ALTER TABLE pre_advice_requests ADD COLUMN payment_verified BOOLEAN DEFAULT FALSE');
        $this->addSql('ALTER TABLE pre_advice_requests ADD COLUMN payment_verified_at DATETIME NULL');
        
        // Add payment failure tracking columns
        $this->addSql('ALTER TABLE pre_advice_requests ADD COLUMN payment_failure_count INT DEFAULT 0');
        $this->addSql('ALTER TABLE pre_advice_requests ADD COLUMN last_payment_failure_reason VARCHAR(255) NULL');
        $this->addSql('ALTER TABLE pre_advice_requests ADD COLUMN last_payment_failure_at DATETIME NULL');
    }

    public function down(Schema $schema): void
    {
        // Remove payment verification columns
        $this->addSql('ALTER TABLE pre_advice_requests DROP COLUMN payment_verified');
        $this->addSql('ALTER TABLE pre_advice_requests DROP COLUMN payment_verified_at');
        
        // Remove payment failure tracking columns
        $this->addSql('ALTER TABLE pre_advice_requests DROP COLUMN payment_failure_count');
        $this->addSql('ALTER TABLE pre_advice_requests DROP COLUMN last_payment_failure_reason');
        $this->addSql('ALTER TABLE pre_advice_requests DROP COLUMN last_payment_failure_at');
    }
}