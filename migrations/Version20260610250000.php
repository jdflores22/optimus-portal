<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260610250000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create barangays table linked to cities';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE barangays (
            id INT AUTO_INCREMENT NOT NULL,
            city_id INT NOT NULL,
            name VARCHAR(150) NOT NULL,
            code VARCHAR(20) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY(id),
            INDEX IDX_97E9312A8BAC62AF (city_id),
            CONSTRAINT FK_97E9312A8BAC62AF FOREIGN KEY (city_id) REFERENCES cities (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE barangays');
    }
}
