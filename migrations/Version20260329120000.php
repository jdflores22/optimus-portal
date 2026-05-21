<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260329120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create notifications table for in-app notifications';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE notifications (
            id INT AUTO_INCREMENT NOT NULL, 
            user_id INT NOT NULL, 
            title VARCHAR(255) NOT NULL, 
            message LONGTEXT NOT NULL, 
            type VARCHAR(50) NOT NULL, 
            is_read TINYINT(1) DEFAULT 0 NOT NULL, 
            action_url VARCHAR(255) DEFAULT NULL, 
            action_text VARCHAR(100) DEFAULT NULL, 
            created_at DATETIME NOT NULL, 
            read_at DATETIME DEFAULT NULL, 
            INDEX IDX_6000B0D3A76ED395 (user_id), 
            INDEX idx_user_read (user_id, is_read), 
            INDEX idx_created_at (created_at), 
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        
        $this->addSql('ALTER TABLE notifications ADD CONSTRAINT FK_6000B0D3A76ED395 FOREIGN KEY (user_id) REFERENCES users (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE notifications DROP FOREIGN KEY FK_6000B0D3A76ED395');
        $this->addSql('DROP TABLE notifications');
    }
}