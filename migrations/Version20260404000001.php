<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add region column to terminals table
 */
final class Version20260404000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add region column to terminals table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE terminals ADD region VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE terminals DROP region');
    }
}
