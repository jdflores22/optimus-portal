<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Change Broker businessName to fullName
 */
final class Version20260120160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Change Broker businessName field to fullName to better reflect that brokers are individuals';
    }

    public function up(Schema $schema): void
    {
        // Rename businessName to fullName in brokers table
        $this->addSql('ALTER TABLE brokers CHANGE business_name full_name VARCHAR(255) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Revert fullName back to businessName in brokers table
        $this->addSql('ALTER TABLE brokers CHANGE full_name business_name VARCHAR(255) NOT NULL');
    }
}