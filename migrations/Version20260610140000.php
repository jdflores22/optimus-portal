<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260610140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add onboarding_guide_completed_steps JSON column to users table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD onboarding_guide_completed_steps JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP onboarding_guide_completed_steps');
    }
}
