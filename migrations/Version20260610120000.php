<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Track one-time consignee welcome modal dismissal on the users table.
 */
final class Version20260610120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add welcome_modal_dismissed_at to users for one-time consignee welcome modal';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD welcome_modal_dismissed_at DATETIME DEFAULT NULL');
        $this->addSql('UPDATE users SET welcome_modal_dismissed_at = NOW() WHERE welcome_modal_dismissed_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP welcome_modal_dismissed_at');
    }
}
