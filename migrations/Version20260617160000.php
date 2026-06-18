<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260617160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track billing PDF template hash so stale PDFs regenerate after template layout changes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE billings ADD pdf_template_hash VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE billings DROP pdf_template_hash');
    }
}
