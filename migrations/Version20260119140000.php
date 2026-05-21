<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260119140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add EDO access logs table';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE edo_access_logs (
            id INT AUTO_INCREMENT NOT NULL, 
            edo_id INT NOT NULL, 
            accessed_by_id INT NOT NULL, 
            accessed_at DATETIME NOT NULL, 
            ip_address VARCHAR(45) NOT NULL, 
            INDEX IDX_EDO_ACCESS_LOGS_EDO_ID (edo_id), 
            INDEX IDX_EDO_ACCESS_LOGS_ACCESSED_BY_ID (accessed_by_id), 
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        
        $this->addSql('ALTER TABLE edo_access_logs ADD CONSTRAINT FK_EDO_ACCESS_LOGS_EDO_ID FOREIGN KEY (edo_id) REFERENCES electronic_delivery_orders (id)');
        $this->addSql('ALTER TABLE edo_access_logs ADD CONSTRAINT FK_EDO_ACCESS_LOGS_ACCESSED_BY_ID FOREIGN KEY (accessed_by_id) REFERENCES users (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE edo_access_logs DROP FOREIGN KEY FK_EDO_ACCESS_LOGS_EDO_ID');
        $this->addSql('ALTER TABLE edo_access_logs DROP FOREIGN KEY FK_EDO_ACCESS_LOGS_ACCESSED_BY_ID');
        $this->addSql('DROP TABLE edo_access_logs');
    }
}