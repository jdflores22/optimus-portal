<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rollback migration for payment version control
 * 
 * This migration provides a complete rollback of the payment version control feature:
 * - Resets all version numbers to 1
 * - Clears all previous_payment_id relationships
 * - Can be used if issues are discovered after deployment
 * 
 * NOTE: This does NOT drop the columns - use Version20260528120000::down() for that
 */
final class Version20260528120002_Rollback extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rollback payment version control data (reset versions and clear relationships)';
    }

    public function up(Schema $schema): void
    {
        // Backup current state before rollback (optional, for safety)
        $this->addSql("
            -- Create backup table with current version data
            CREATE TABLE IF NOT EXISTS payments_version_backup AS
            SELECT id, version, previous_payment_id, created_at
            FROM payments
            WHERE version > 1 OR previous_payment_id IS NOT NULL
        ");
        
        // Reset all version numbers to 1
        $this->addSql('UPDATE payments SET version = 1');
        
        // Clear all previous_payment_id relationships
        $this->addSql('UPDATE payments SET previous_payment_id = NULL');
        
        $this->write('Rollback complete: All payments reset to version 1 with no relationships');
        $this->write('Backup created in payments_version_backup table');
    }

    public function down(Schema $schema): void
    {
        // Restore from backup if it exists
        $this->addSql("
            UPDATE payments p
            INNER JOIN payments_version_backup b ON p.id = b.id
            SET p.version = b.version, p.previous_payment_id = b.previous_payment_id
        ");
        
        // Drop backup table
        $this->addSql('DROP TABLE IF EXISTS payments_version_backup');
        
        $this->write('Restored payment versions from backup');
    }
    
    public function postUp(Schema $schema): void
    {
        $connection = $this->connection;
        
        // Verify rollback was successful
        $version1Count = $connection->fetchOne('SELECT COUNT(*) FROM payments WHERE version = 1');
        $versionGt1Count = $connection->fetchOne('SELECT COUNT(*) FROM payments WHERE version > 1');
        $withPreviousCount = $connection->fetchOne('SELECT COUNT(*) FROM payments WHERE previous_payment_id IS NOT NULL');
        $backupCount = $connection->fetchOne('SELECT COUNT(*) FROM payments_version_backup');
        
        $this->write('');
        $this->write('Rollback Verification:');
        $this->write("- Payments with version = 1: {$version1Count}");
        $this->write("- Payments with version > 1: {$versionGt1Count} (expected: 0)");
        $this->write("- Payments with previous_payment_id: {$withPreviousCount} (expected: 0)");
        $this->write("- Records backed up: {$backupCount}");
        
        if ($versionGt1Count > 0 || $withPreviousCount > 0) {
            $this->write('');
            $this->write('WARNING: Rollback incomplete! Some payments still have version > 1 or previous_payment_id set.');
        } else {
            $this->write('');
            $this->write('SUCCESS: Rollback completed successfully!');
        }
    }
}
