<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260611120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add AWAITING_FINAL_APPROVAL accreditation status for evaluator-approved submissions pending Shipping Admin sign-off';
    }

    public function up(Schema $schema): void
    {
        // Evaluator-approved but not yet final-approved → distinct status (not APPROVED)
        $this->addSql("
            UPDATE accreditation_submissions
            SET status = 'AWAITING_FINAL_APPROVAL'
            WHERE status = 'APPROVED'
              AND evaluator_id IS NOT NULL
              AND final_approver_id IS NULL
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("
            UPDATE accreditation_submissions
            SET status = 'APPROVED'
            WHERE status = 'AWAITING_FINAL_APPROVAL'
        ");
    }
}
