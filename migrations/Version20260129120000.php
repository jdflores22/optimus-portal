<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add API token fields to truckers table
 */
final class Version20260129120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add API token fields to truckers table for mobile authentication';
    }

    public function up(Schema $schema): void
    {
        // Add API token fields to truckers table
        $this->addSql('ALTER TABLE truckers ADD api_token_hash VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE truckers ADD api_token_expires_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE truckers ADD last_activity_at DATETIME DEFAULT NULL');
        
        // Add index for API token hash for faster lookups
        $this->addSql('CREATE INDEX IDX_api_token_hash ON truckers (api_token_hash)');
        
        // Add index for token expiration for cleanup queries
        $this->addSql('CREATE INDEX IDX_api_token_expires_at ON truckers (api_token_expires_at)');
    }

    public function down(Schema $schema): void
    {
        // Remove indexes first
        $this->addSql('DROP INDEX IDX_api_token_hash ON truckers');
        $this->addSql('DROP INDEX IDX_api_token_expires_at ON truckers');
        
        // Remove API token fields from truckers table
        $this->addSql('ALTER TABLE truckers DROP api_token_hash');
        $this->addSql('ALTER TABLE truckers DROP api_token_expires_at');
        $this->addSql('ALTER TABLE truckers DROP last_activity_at');
    }
}