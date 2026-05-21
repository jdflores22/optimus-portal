<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add new fields to shipment_records table and consignee relationship
 */
final class Version20260120170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add new fields to shipment_records table and consignee relationship';
    }

    public function up(Schema $schema): void
    {
        // Add new fields to shipment_records table
        $this->addSql('ALTER TABLE shipment_records ADD consignee_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE shipment_records ADD delivery_order_no VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE shipment_records ADD bl_no VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE shipment_records ADD vessel VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE shipment_records ADD voyage VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE shipment_records ADD lloyds_no VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE shipment_records ADD general_declaration_dt DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE shipment_records ADD vessel_custom_no VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE shipment_records ADD agent_custom_reg_no VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE shipment_records ADD cust_id VARCHAR(100) DEFAULT NULL');
        
        // Add foreign key constraint for consignee
        $this->addSql('ALTER TABLE shipment_records ADD CONSTRAINT FK_shipment_consignee FOREIGN KEY (consignee_id) REFERENCES users (id)');
        
        // Add index for consignee lookup
        $this->addSql('CREATE INDEX IDX_shipment_consignee ON shipment_records (consignee_id)');
    }

    public function down(Schema $schema): void
    {
        // Remove foreign key and index
        $this->addSql('ALTER TABLE shipment_records DROP FOREIGN KEY FK_shipment_consignee');
        $this->addSql('DROP INDEX IDX_shipment_consignee ON shipment_records');
        
        // Remove new fields
        $this->addSql('ALTER TABLE shipment_records DROP consignee_id');
        $this->addSql('ALTER TABLE shipment_records DROP delivery_order_no');
        $this->addSql('ALTER TABLE shipment_records DROP bl_no');
        $this->addSql('ALTER TABLE shipment_records DROP vessel');
        $this->addSql('ALTER TABLE shipment_records DROP voyage');
        $this->addSql('ALTER TABLE shipment_records DROP lloyds_no');
        $this->addSql('ALTER TABLE shipment_records DROP general_declaration_dt');
        $this->addSql('ALTER TABLE shipment_records DROP vessel_custom_no');
        $this->addSql('ALTER TABLE shipment_records DROP agent_custom_reg_no');
        $this->addSql('ALTER TABLE shipment_records DROP cust_id');
    }
}