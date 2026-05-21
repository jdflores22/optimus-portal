<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add security fields to pending_users table for token disabling functionality
 */
final class Version20260330130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add disabledUntil field to pending_users table for security monitoring';
    }

    public function up(Schema $schema): void
    {
        // Add disabledUntil column to pending_users table
        $this->addSql('ALTER TABLE pending_users ADD disabled_until DATETIME DEFAULT NULL');
        
        // Update status column to include temporarily_disabled option
        $this->addSql('ALTER TABLE pending_users MODIFY status VARCHAR(20) DEFAULT \'pending\'');
    }

    public function down(Schema $schema): void
    {
        // Remove disabledUntil column
        $this->addSql('ALTER TABLE pending_users DROP disabled_until');
    }
}