<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add container and commodity information fields to shipment_records table
 */
final class Version20260122000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add container and commodity information fields to shipment_records table';
    }

    public function up(Schema $schema): void
    {
        // Add container information columns
        $this->addSql('ALTER TABLE shipment_records ADD container_number VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE shipment_records ADD container_type VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE shipment_records ADD container_size VARCHAR(50) DEFAULT NULL');
        
        // Add commodity information columns
        $this->addSql('ALTER TABLE shipment_records ADD commodity VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE shipment_records ADD commodity_pcs VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE shipment_records ADD commodity_qty VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE shipment_records ADD net_wt_kgm VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE shipment_records ADD meas_cbm VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE shipment_records ADD empty_return_address TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Remove container information columns
        $this->addSql('ALTER TABLE shipment_records DROP COLUMN container_number');
        $this->addSql('ALTER TABLE shipment_records DROP COLUMN container_type');
        $this->addSql('ALTER TABLE shipment_records DROP COLUMN container_size');
        
        // Remove commodity information columns
        $this->addSql('ALTER TABLE shipment_records DROP COLUMN commodity');
        $this->addSql('ALTER TABLE shipment_records DROP COLUMN commodity_pcs');
        $this->addSql('ALTER TABLE shipment_records DROP COLUMN commodity_qty');
        $this->addSql('ALTER TABLE shipment_records DROP COLUMN net_wt_kgm');
        $this->addSql('ALTER TABLE shipment_records DROP COLUMN meas_cbm');
        $this->addSql('ALTER TABLE shipment_records DROP COLUMN empty_return_address');
    }
}