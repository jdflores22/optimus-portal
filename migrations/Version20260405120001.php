<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create PaymentType and PaymentStatus enum types
 */
final class Version20260405120001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create PaymentType enum (manifest_access, final_payment) and PaymentStatus enum (pending_validation, verified, rejected)';
    }

    public function up(Schema $schema): void
    {
        // MySQL doesn't have native enum types like PostgreSQL
        // We'll use CHECK constraints on the payments table instead
        // This migration serves as documentation for the payment types and statuses
    }

    public function down(Schema $schema): void
    {
        // No action needed
    }
}
