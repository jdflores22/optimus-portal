<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create WorkflowState enum type for manifest workflow
 */
final class Version20260405120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create WorkflowState enum type with values: pending_payment, payment_verified, noa_generated, bl_uploaded, billing_generated, payment_submitted, edo_generated';
    }

    public function up(Schema $schema): void
    {
        // MySQL doesn't have native enum types like PostgreSQL
        // We'll use CHECK constraints on the manifests table instead
        // This migration serves as documentation for the workflow states
    }

    public function down(Schema $schema): void
    {
        // No action needed
    }
}
