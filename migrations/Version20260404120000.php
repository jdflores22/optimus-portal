<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260404120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add 20ft and 40ft container capacity columns to shipping_line_terminal_allocations table';
    }

    public function up(Schema $schema): void
    {
        // Add columns for 20ft and 40ft container capacity
        $this->addSql('ALTER TABLE shipping_line_terminal_allocations ADD capacity_20ft INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE shipping_line_terminal_allocations ADD capacity_40ft INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shipping_line_terminal_allocations DROP capacity_20ft');
        $this->addSql('ALTER TABLE shipping_line_terminal_allocations DROP capacity_40ft');
    }
}
