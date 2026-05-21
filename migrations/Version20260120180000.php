<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add CUST Status and CUST REF fields to shipment_records table
 */
final class Version20260120180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add CUST Status and CUST REF fields to shipment_records table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shipment_records ADD cust_status VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE shipment_records ADD cust_ref VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shipment_records DROP cust_status');
        $this->addSql('ALTER TABLE shipment_records DROP cust_ref');
    }
}