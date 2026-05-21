<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add accessResult field to edo_access_logs table
 */
final class Version20260407120002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add accessResult field to edo_access_logs table to track granted/denied access attempts';
    }

    public function up(Schema $schema): void
    {
        // Add accessResult column
        $this->addSql('ALTER TABLE edo_access_logs ADD COLUMN access_result VARCHAR(20) NOT NULL DEFAULT "granted"');
    }

    public function down(Schema $schema): void
    {
        // Remove accessResult column
        $this->addSql('ALTER TABLE edo_access_logs DROP COLUMN access_result');
    }
}
