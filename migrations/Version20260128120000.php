<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260128120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Terminal Team and Trucker user types';
    }

    public function up(Schema $schema): void
    {
        // Create terminal_team_users table
        $this->addSql('CREATE TABLE terminal_team_users (
            id INT NOT NULL,
            first_name VARCHAR(100) NOT NULL,
            last_name VARCHAR(100) NOT NULL,
            department VARCHAR(100) NOT NULL,
            terminal_permissions JSON NOT NULL,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Create truckers table
        $this->addSql('CREATE TABLE truckers (
            id INT NOT NULL,
            first_name VARCHAR(100) NOT NULL,
            last_name VARCHAR(100) NOT NULL,
            phone_number VARCHAR(20) DEFAULT NULL,
            license_number VARCHAR(50) DEFAULT NULL,
            company_name VARCHAR(100) DEFAULT NULL,
            truck_plate_number VARCHAR(20) DEFAULT NULL,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Add foreign key constraints
        $this->addSql('ALTER TABLE terminal_team_users ADD CONSTRAINT FK_TERMINAL_TEAM_USER_ID FOREIGN KEY (id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE truckers ADD CONSTRAINT FK_TRUCKER_USER_ID FOREIGN KEY (id) REFERENCES users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // Drop foreign key constraints
        $this->addSql('ALTER TABLE terminal_team_users DROP FOREIGN KEY FK_TERMINAL_TEAM_USER_ID');
        $this->addSql('ALTER TABLE truckers DROP FOREIGN KEY FK_TRUCKER_USER_ID');

        // Drop tables
        $this->addSql('DROP TABLE terminal_team_users');
        $this->addSql('DROP TABLE truckers');
    }
}