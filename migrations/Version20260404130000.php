<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260404130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add password reset OTP fields to users table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD password_reset_otp VARCHAR(6) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD password_reset_otp_expires_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP password_reset_otp');
        $this->addSql('ALTER TABLE users DROP password_reset_otp_expires_at');
    }
}
