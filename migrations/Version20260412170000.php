<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Multi-Tenant Shipping Line - Task 1.5: Data Migration to Default Shipping Line
 * 
 * Migrates all existing records to the default shipping line (CMA CGM, ID: 2).
 * This ensures all historical data is associated with a shipping line before
 * making the shipping_line_id columns NOT NULL.
 */
final class Version20260412170000 extends AbstractMigration
{
    private const DEFAULT_SHIPPING_LINE_ID = 2; // CMA CGM

    public function getDescription(): string
    {
        return 'Migrate all existing records to default shipping line (CMA CGM)';
    }

    public function up(Schema $schema): void
    {
        // Log migration start
        $this->write('Starting data migration to default shipping line (ID: ' . self::DEFAULT_SHIPPING_LINE_ID . ')');
        
        // Migrate manifests
        $this->addSql('UPDATE manifests SET shipping_line_id = ' . self::DEFAULT_SHIPPING_LINE_ID . ' WHERE shipping_line_id IS NULL');
        $this->write('Migrated manifests to default shipping line');
        
        // Migrate payments
        $this->addSql('UPDATE payments SET shipping_line_id = ' . self::DEFAULT_SHIPPING_LINE_ID . ' WHERE shipping_line_id IS NULL');
        $this->write('Migrated payments to default shipping line');
        
        // Migrate EDO payments
        $this->addSql('UPDATE payments_edo SET shipping_line_id = ' . self::DEFAULT_SHIPPING_LINE_ID . ' WHERE shipping_line_id IS NULL');
        $this->write('Migrated EDO payments to default shipping line');
        
        // Migrate electronic delivery orders
        $this->addSql('UPDATE electronic_delivery_orders SET shipping_line_id = ' . self::DEFAULT_SHIPPING_LINE_ID . ' WHERE shipping_line_id IS NULL');
        $this->write('Migrated electronic delivery orders to default shipping line');
        
        // Migrate accreditation submissions
        $this->addSql('UPDATE accreditation_submissions SET shipping_line_id = ' . self::DEFAULT_SHIPPING_LINE_ID . ' WHERE shipping_line_id IS NULL');
        $this->write('Migrated accreditation submissions to default shipping line');
        
        // Note: Notifications remain NULL as they are optional
        
        $this->write('Data migration completed successfully');
    }

    public function down(Schema $schema): void
    {
        // Rollback: Set shipping_line_id back to NULL for all migrated records
        $this->write('Rolling back data migration - setting shipping_line_id to NULL');
        
        $this->addSql('UPDATE manifests SET shipping_line_id = NULL WHERE shipping_line_id = ' . self::DEFAULT_SHIPPING_LINE_ID);
        $this->addSql('UPDATE payments SET shipping_line_id = NULL WHERE shipping_line_id = ' . self::DEFAULT_SHIPPING_LINE_ID);
        $this->addSql('UPDATE payments_edo SET shipping_line_id = NULL WHERE shipping_line_id = ' . self::DEFAULT_SHIPPING_LINE_ID);
        $this->addSql('UPDATE electronic_delivery_orders SET shipping_line_id = NULL WHERE shipping_line_id = ' . self::DEFAULT_SHIPPING_LINE_ID);
        $this->addSql('UPDATE accreditation_submissions SET shipping_line_id = NULL WHERE shipping_line_id = ' . self::DEFAULT_SHIPPING_LINE_ID);
        
        $this->write('Rollback completed');
    }
}
