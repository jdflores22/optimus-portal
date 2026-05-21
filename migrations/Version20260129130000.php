<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add missing columns to truckers table for Terminal Team FREE-ADVICE system
 */
final class Version20260129130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add missing columns to truckers table: company_name, phone_number, license_number, truck_plate_number, api_token_hash, api_token_expires_at, last_activity_at';
    }

    public function up(Schema $schema): void
    {
        // Check if truckers table exists, if not create it
        if (!$schema->hasTable('truckers')) {
            $this->addSql('CREATE TABLE truckers (
                id INT AUTO_INCREMENT NOT NULL,
                first_name VARCHAR(100) NOT NULL,
                last_name VARCHAR(100) NOT NULL,
                phone_number VARCHAR(20) DEFAULT NULL,
                license_number VARCHAR(50) DEFAULT NULL,
                company_name VARCHAR(100) DEFAULT NULL,
                truck_plate_number VARCHAR(20) DEFAULT NULL,
                api_token_hash VARCHAR(255) DEFAULT NULL,
                api_token_expires_at DATETIME DEFAULT NULL,
                last_activity_at DATETIME DEFAULT NULL,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        } else {
            // Add missing columns if they don't exist
            $this->addSql('ALTER TABLE truckers 
                ADD COLUMN IF NOT EXISTS phone_number VARCHAR(20) DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS license_number VARCHAR(50) DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS company_name VARCHAR(100) DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS truck_plate_number VARCHAR(20) DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS api_token_hash VARCHAR(255) DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS api_token_expires_at DATETIME DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS last_activity_at DATETIME DEFAULT NULL'
            );
        }
    }

    public function down(Schema $schema): void
    {
        // Remove the added columns
        if ($schema->hasTable('truckers')) {
            $this->addSql('ALTER TABLE truckers 
                DROP COLUMN IF EXISTS phone_number,
                DROP COLUMN IF EXISTS license_number,
                DROP COLUMN IF EXISTS company_name,
                DROP COLUMN IF EXISTS truck_plate_number,
                DROP COLUMN IF EXISTS api_token_hash,
                DROP COLUMN IF EXISTS api_token_expires_at,
                DROP COLUMN IF EXISTS last_activity_at'
            );
        }
    }
}